#!/usr/bin/env python3
"""Technical SEO checks against a live site, for CI and before/after a deploy.

    python3 tools/seo-check.py https://beta.domainbrothers.com --env staging
    python3 tools/seo-check.py https://www.domainbrothers.com --env production --sample 60

Site checks: robots.txt (production: a Sitemap line and no site-wide Disallow; staging: Disallow: /),
the sitemap index and every child sitemap (200, valid XML, absolute same-host <loc>s).

Page checks, on key pages plus a sample of sitemap URLs:
  errors   - status is not 200; missing or duplicate <title>; missing, relative, cross-host or
             parameterised canonical, or a canonical whose target does not answer 200;
             noindex on a production sitemap page (meta robots or X-Robots-Tag) or a staging page
             without noindex; JSON-LD that does not parse; a node without @type; a relative @id;
             a BreadcrumbList whose positions are not 1..n; a Product offer without price/currency.
  warnings - missing meta description; title over 65 or description over 160 characters;
             not exactly one <h1>.

Exit code 1 when any error is found. Requests are sequential with a pause (--delay), because the
staging host answers 508 under parallel load. Only the standard library is used.
"""
from __future__ import annotations

import argparse
import html
import json
import random
import re
import sys
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

UA = "Mozilla/5.0 (compatible; db-seo-check/1.0)"
KEY_PATHS = ["/", "/all-domains/", "/news/", "/domain-tools/", "/sell-your-domains/", "/about-us/", "/faqs/"]
SM_NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):  # noqa: D401 - urllib signature
        return None


_opener = urllib.request.build_opener(_NoRedirect)


def fetch(url: str, timeout: int = 30, retries: int = 2):
    """GET without following redirects. Returns (status, headers dict lower-cased, body str)."""
    for attempt in range(retries + 1):
        req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "text/html,application/xml;q=0.9,*/*;q=0.8"})
        try:
            with _opener.open(req, timeout=timeout) as r:
                return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            if e.code in (508, 503, 429) and attempt < retries:
                time.sleep(5 * (attempt + 1))
                continue
            body = e.read().decode("utf-8", "replace") if e.fp else ""
            return e.code, {k.lower(): v for k, v in (e.headers or {}).items()}, body
        except (urllib.error.URLError, TimeoutError) as e:
            if attempt < retries:
                time.sleep(5)
                continue
            return 0, {}, str(e)
    return 0, {}, ""


class _HeadParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.titles: list[str] = []
        self.canonicals: list[str] = []
        self.robots: list[str] = []
        self.description: list[str] = []
        self.h1 = 0
        self.jsonld: list[str] = []
        self._in_title = False
        self._in_ld = False
        self._buf: list[str] = []
        self._in_svg = 0

    def handle_starttag(self, tag, attrs):
        a = {k.lower(): (v or "") for k, v in attrs}
        if tag == "svg":
            self._in_svg += 1
        if tag == "title" and not self._in_svg:
            self._in_title, self._buf = True, []
        elif tag == "link" and "canonical" in a.get("rel", "").lower().split():
            self.canonicals.append(a.get("href", ""))
        elif tag == "meta":
            name = a.get("name", "").lower()
            if name in ("robots", "googlebot"):
                self.robots.append(a.get("content", "").lower())
            elif name == "description":
                self.description.append(a.get("content", ""))
        elif tag == "h1":
            self.h1 += 1
        elif tag == "script" and a.get("type", "").lower() == "application/ld+json":
            self._in_ld, self._buf = True, []

    def handle_endtag(self, tag):
        if tag == "svg" and self._in_svg:
            self._in_svg -= 1
        if tag == "title" and self._in_title:
            self.titles.append("".join(self._buf).strip())
            self._in_title = False
        elif tag == "script" and self._in_ld:
            self.jsonld.append("".join(self._buf))
            self._in_ld = False

    def handle_data(self, data):
        if self._in_title or self._in_ld:
            self._buf.append(data)


def parse_page(body: str) -> _HeadParser:
    p = _HeadParser()
    p.feed(body)
    return p


def _nodes(data):
    if isinstance(data, list):
        for d in data:
            yield from _nodes(d)
    elif isinstance(data, dict):
        if "@graph" in data:
            yield from _nodes(data["@graph"])
        else:
            yield data


def check_jsonld(blocks: list[str]) -> list[str]:
    """Pure: errors in the page's JSON-LD blocks."""
    errs = []
    for i, raw in enumerate(blocks, 1):
        try:
            data = json.loads(raw)
        except ValueError as e:
            errs.append(f"JSON-LD block {i} does not parse ({e.msg})")
            continue
        for node in _nodes(data):
            if "@type" not in node and "@id" not in node:
                errs.append(f"JSON-LD block {i}: node without @type")
            def walk(v):
                if isinstance(v, dict):
                    for k, x in v.items():
                        if k == "@id" and isinstance(x, str) and not re.match(r"^https?://", x):
                            errs.append(f"JSON-LD block {i}: relative @id {x!r}")
                        walk(x)
                elif isinstance(v, list):
                    for x in v:
                        walk(x)
            walk(node)
            types = node.get("@type") if isinstance(node.get("@type"), list) else [node.get("@type")]
            if "BreadcrumbList" in types:
                pos = []
                for it in node.get("itemListElement", []) or []:
                    try:
                        pos.append(int(it.get("position")))
                    except (TypeError, ValueError):
                        pos.append(-1)
                if pos != list(range(1, len(pos) + 1)):
                    errs.append(f"JSON-LD block {i}: BreadcrumbList positions {pos} are not 1..n")
            if "Product" in types and "offers" in node:
                offers = node["offers"] if isinstance(node["offers"], list) else [node["offers"]]
                for o in offers:
                    if not o.get("price") and not o.get("lowPrice"):
                        errs.append(f"JSON-LD block {i}: Product offer without price")
                    if not o.get("priceCurrency"):
                        errs.append(f"JSON-LD block {i}: Product offer without priceCurrency")
    return errs


def check_page(url: str, status: int, headers: dict, body: str, env: str, in_sitemap: bool) -> tuple[list[str], list[str], str]:
    """Pure: (errors, warnings, canonical) for one fetched page."""
    errs: list[str] = []
    warns: list[str] = []
    if status != 200:
        loc = headers.get("location", "")
        return [f"status {status}" + (f" -> {loc}" if loc else "")], [], ""
    p = parse_page(body)
    titles = [t for t in p.titles if t]
    if not titles:
        errs.append("missing <title>")
    elif len(p.titles) > 1:
        errs.append(f"{len(p.titles)} <title> elements")
    elif len(html.unescape(titles[0])) > 65:
        warns.append(f"title is {len(html.unescape(titles[0]))} characters")
    if not p.description or not p.description[0].strip():
        warns.append("missing meta description")
    elif len(p.description[0]) > 160:
        warns.append(f"meta description is {len(p.description[0])} characters")
    if p.h1 != 1:
        warns.append(f"{p.h1} <h1> elements")

    canonical = ""
    if not p.canonicals:
        errs.append("missing canonical")
    else:
        canonical = p.canonicals[0]
        if len(set(p.canonicals)) > 1:
            errs.append(f"{len(p.canonicals)} different canonicals")
        cu = urlsplit(canonical)
        if not cu.scheme or not cu.netloc:
            errs.append(f"relative canonical {canonical!r}")
        elif cu.netloc != urlsplit(url).netloc:
            errs.append(f"canonical on another host: {canonical}")
        elif cu.query:
            errs.append(f"canonical has a query string: {canonical}")

    robots = " ".join(p.robots + [headers.get("x-robots-tag", "").lower()])
    noindex = "noindex" in robots
    if env == "production" and in_sitemap and noindex:
        errs.append("noindex on a production page listed in the sitemap")
    if env == "staging" and not noindex:
        errs.append("staging page without noindex")
    errs.extend(check_jsonld(p.jsonld))
    return errs, warns, canonical


def check_robots(body: str, env: str) -> list[str]:
    """Pure: errors in robots.txt."""
    errs = []
    groups = re.split(r"\n\s*\n", body.replace("\r", ""))
    star_disallow_all = any(re.search(r"(?im)^user-agent:\s*\*\s*$", g) and re.search(r"(?im)^disallow:\s*/\s*$", g) for g in groups)
    if env == "production":
        if star_disallow_all:
            errs.append("robots.txt disallows the whole site on production")
        if not re.search(r"(?im)^sitemap:\s*https?://", body):
            errs.append("robots.txt has no Sitemap line")
    elif not star_disallow_all:
        errs.append("staging robots.txt does not disallow the whole site")
    return errs


def sitemap_locs(xml_text: str) -> tuple[list[str], list[str]]:
    """Pure: (child sitemaps, page URLs) from a sitemap or sitemap index."""
    root = ET.fromstring(xml_text.encode("utf-8"))
    children = [e.text.strip() for e in root.iter(SM_NS + "sitemap") for e in [e.find(SM_NS + "loc")] if e is not None and e.text]
    urls = [e.text.strip() for e in root.iter(SM_NS + "url") for e in [e.find(SM_NS + "loc")] if e is not None and e.text]
    return children, urls


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("base", help="site root, e.g. https://www.domainbrothers.com")
    ap.add_argument("--env", choices=["production", "staging"], required=True)
    ap.add_argument("--sample", type=int, default=40, help="sitemap URLs to check besides the key pages")
    ap.add_argument("--delay", type=float, default=1.5, help="seconds between requests")
    ap.add_argument("--seed", type=int, default=0)
    args = ap.parse_args()
    base = args.base.rstrip("/")
    host = urlsplit(base).netloc
    errors: list[str] = []
    warnings: list[str] = []

    def pause():
        time.sleep(args.delay)

    st, _, body = fetch(base + "/robots.txt")
    if st != 200:
        errors.append(f"/robots.txt: status {st}")
    else:
        errors += [f"/robots.txt: {e}" for e in check_robots(body, args.env)]
    pause()

    all_urls: list[str] = []
    st, _, body = fetch(base + "/sitemap_index.xml")
    if st != 200:
        errors.append(f"/sitemap_index.xml: status {st}")
        children = []
    else:
        try:
            children, all_urls = sitemap_locs(body)
        except ET.ParseError as e:
            errors.append(f"/sitemap_index.xml: invalid XML ({e})")
            children = []
    for child in children:
        pause()
        st, _, body = fetch(child)
        if st != 200:
            errors.append(f"{child}: status {st}")
            continue
        try:
            _, urls = sitemap_locs(body)
        except ET.ParseError as e:
            errors.append(f"{child}: invalid XML ({e})")
            continue
        if not urls:
            warnings.append(f"{child}: empty sitemap")
        for u in urls:
            if urlsplit(u).netloc != host:
                errors.append(f"{child}: <loc> on another host: {u}")
        all_urls += urls

    in_sitemap = set(all_urls)
    rnd = random.Random(args.seed)
    sample = rnd.sample(all_urls, min(args.sample, len(all_urls))) if all_urls else []
    pages = list(dict.fromkeys([base + p for p in KEY_PATHS] + sample))
    canon_seen: dict[str, int] = {}
    for url in pages:
        pause()
        st, headers, body = fetch(url)
        errs, warns, canonical = check_page(url, st, headers, body, args.env, url in in_sitemap)
        errors += [f"{url}: {e}" for e in errs]
        warnings += [f"{url}: {w}" for w in warns]
        if canonical and canonical != url and urlsplit(canonical).netloc == host:
            if canonical not in canon_seen:
                pause()
                canon_seen[canonical] = fetch(canonical)[0]
            if canon_seen[canonical] != 200:
                errors.append(f"{url}: canonical {canonical} answers {canon_seen[canonical]}")

    print(f"Checked robots.txt, {len(children)} sitemaps ({len(all_urls)} URLs) and {len(pages)} pages on {base} ({args.env}).")
    for w in warnings:
        print("WARN ", w)
    for e in errors:
        print("ERROR", e)
    print(f"\n{len(errors)} errors, {len(warnings)} warnings")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
