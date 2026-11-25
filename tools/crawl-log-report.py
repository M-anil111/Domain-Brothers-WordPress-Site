#!/usr/bin/env python3
"""Crawl report from web server access logs (Apache/LiteSpeed "combined" format).

Answers the questions an SEO review asks of raw logs:
  * How often do search and AI crawlers visit, and which sections do they spend time on?
  * Which URLs return 404 or 5xx, and on which days did errors spike?
  * How much crawling is wasted on URLs that should not be crawled
    (query strings, filters, private routes, redirects, assets)?

Hostinger keeps access logs under hPanel > Websites > Access logs (download the .gz files).
Usage:
    python3 tools/crawl-log-report.py access.log [more.log.gz ...] [--days 30] [--json out.json]

Only the standard library is used. Bot claims are verified with reverse and forward DNS when
--verify is given (slower; requires network). Without it, a user agent claiming to be Googlebot
is counted as Googlebot and flagged as "unverified".
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import gzip
import ipaddress
import json
import re
import socket
import sys
from urllib.parse import urlsplit, parse_qsl

LINE_RE = re.compile(
    r'(?P<ip>\S+) \S+ \S+ \[(?P<time>[^\]]+)\] "(?P<method>[A-Z]+) (?P<url>\S+) [^"]*" '
    r'(?P<status>\d{3}) (?P<size>\S+)(?: "(?P<ref>[^"]*)" "(?P<ua>[^"]*)")?'
)

# Search engine and AI crawlers, matched on the user agent (first match wins).
BOTS = [
    ("Googlebot", re.compile(r"Googlebot|Google-InspectionTool|GoogleOther", re.I), ("googlebot.com", "google.com")),
    ("Bingbot", re.compile(r"bingbot|BingPreview", re.I), ("search.msn.com",)),
    ("Applebot", re.compile(r"Applebot", re.I), ("applebot.apple.com",)),
    ("DuckDuckBot", re.compile(r"DuckDuckBot", re.I), ()),
    ("YandexBot", re.compile(r"YandexBot", re.I), ("yandex.ru", "yandex.net", "yandex.com")),
    ("OAI-SearchBot", re.compile(r"OAI-SearchBot", re.I), ()),
    ("ChatGPT-User", re.compile(r"ChatGPT-User", re.I), ()),
    ("GPTBot", re.compile(r"GPTBot", re.I), ()),
    ("PerplexityBot", re.compile(r"Perplexity", re.I), ()),
    ("ClaudeBot", re.compile(r"ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai", re.I), ()),
    ("CCBot", re.compile(r"CCBot", re.I), ()),
    ("Bytespider", re.compile(r"Bytespider", re.I), ()),
    ("SEO tools", re.compile(r"AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|DataForSeoBot|serpstatbot|SERankingBacklinksBot", re.I), ()),
]

# Paths that should never use crawl budget (mirrors db_crawl_robots_txt()).
PRIVATE_PREFIXES = (
    "/wp-admin/", "/my-wishlist/", "/my-account/", "/account/", "/seller/", "/buy-now/",
    "/payment-plan-setup/", "/thank-you/", "/make-an-offer/", "/register/", "/sign-in/",
    "/connect-store/", "/search/", "/wp-login.php",
)
FACET_PARAMS = {"s", "q", "sort", "order", "orderby", "tld", "ext", "max", "min", "len", "length", "view", "filter", "cat", "price"}
TRACKING_PARAMS = re.compile(r"^(utm_|gclid$|fbclid$|msclkid$|mc_|_ga$|ref$)")
ASSET_RE = re.compile(r"\.(?:css|js|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|map|xml|txt)$", re.I)


def open_log(path: str):
    return gzip.open(path, "rt", encoding="utf-8", errors="replace") if path.endswith(".gz") else open(path, encoding="utf-8", errors="replace")


def parse_time(raw: str) -> dt.datetime | None:
    try:
        return dt.datetime.strptime(raw, "%d/%b/%Y:%H:%M:%S %z")
    except ValueError:
        return None


def bot_of(ua: str):
    for name, rx, domains in BOTS:
        if rx.search(ua or ""):
            return name, domains
    return None, ()


_dns_cache: dict[str, bool] = {}


def verify_bot(ip: str, domains: tuple) -> bool:
    """Reverse DNS must end in one of the domains, and forward DNS must map back to the IP."""
    if not domains:
        return False
    if ip in _dns_cache:
        return _dns_cache[ip]
    ok = False
    try:
        ipaddress.ip_address(ip)
        host = socket.gethostbyaddr(ip)[0].lower()
        if any(host == d or host.endswith("." + d) for d in domains):
            ok = ip in {ai[4][0] for ai in socket.getaddrinfo(host, None)}
    except (OSError, ValueError):
        ok = False
    _dns_cache[ip] = ok
    return ok


def section(path: str) -> str:
    parts = [p for p in path.split("/") if p]
    if not parts:
        return "/"
    first = parts[0]
    if first in ("domains", "domain-category", "domain-tools", "collections", "news", "category", "our-team", "wp-content", "wp-json", "page"):
        return "/" + first + "/"
    return "/(pages)/"


def waste_reason(path: str, query: str, status: int) -> str | None:
    keys = {k.lower() for k, _ in parse_qsl(query, keep_blank_values=True)}
    if status in (301, 302, 307, 308):
        return "redirect"
    if status == 404 or status == 410:
        return "not found"
    if status >= 500:
        return "server error"
    if any(path.startswith(p) for p in PRIVATE_PREFIXES):
        return "private route"
    if keys & FACET_PARAMS:
        return "filter or search URL"
    if keys and all(TRACKING_PARAMS.match(k) for k in keys):
        return "tracking parameters"
    if keys:
        return "other query string"
    if re.search(r"/page/\d+/?$", path):
        return "deep pagination" if int(re.search(r"/page/(\d+)", path).group(1)) > 5 else None
    return None


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("logs", nargs="+")
    ap.add_argument("--days", type=int, default=0, help="only the last N days (0 = everything)")
    ap.add_argument("--verify", action="store_true", help="verify crawler IPs with reverse/forward DNS")
    ap.add_argument("--json", help="also write the report as JSON")
    ap.add_argument("--top", type=int, default=15)
    args = ap.parse_args()

    total = bad_lines = 0
    by_bot = collections.Counter()
    bot_unverified = collections.Counter()
    bot_sections = collections.defaultdict(collections.Counter)
    bot_status = collections.defaultdict(collections.Counter)
    errors_by_day = collections.defaultdict(collections.Counter)
    error_urls = {404: collections.Counter(), 500: collections.Counter()}
    waste = collections.Counter()
    waste_urls = collections.defaultdict(collections.Counter)
    google_urls = collections.Counter()
    google_days = collections.Counter()
    cutoff = None
    if args.days:
        cutoff = dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=args.days)

    for path in args.logs:
        with open_log(path) as fh:
            for line in fh:
                m = LINE_RE.match(line)
                if not m:
                    bad_lines += 1
                    continue
                when = parse_time(m["time"])
                if when is None or (cutoff and when < cutoff):
                    continue
                total += 1
                status = int(m["status"])
                parts = urlsplit(m["url"])
                url_path = parts.path or "/"
                day = when.date().isoformat()
                if status >= 500:
                    errors_by_day[day]["5xx"] += 1
                    error_urls[500][m["url"]] += 1
                elif status == 404:
                    errors_by_day[day]["404"] += 1
                    error_urls[404][m["url"]] += 1

                name, domains = bot_of(m["ua"] or "")
                if not name:
                    continue
                by_bot[name] += 1
                if domains and args.verify and not verify_bot(m["ip"], domains):
                    bot_unverified[name] += 1
                    continue
                bot_sections[name][section(url_path)] += 1
                bot_status[name][f"{status // 100}xx"] += 1
                if name in ("Googlebot", "Bingbot") and not ASSET_RE.search(url_path):
                    reason = waste_reason(url_path, parts.query, status)
                    if reason:
                        waste[reason] += 1
                        waste_urls[reason][m["url"]] += 1
                if name == "Googlebot":
                    google_urls[m["url"]] += 1
                    google_days[day] += 1

    # Error spikes: a day with more than twice the median and at least 20 errors.
    spikes = []
    for kind in ("404", "5xx"):
        series = sorted(v[kind] for v in errors_by_day.values() if v[kind])
        if not series:
            continue
        median = series[len(series) // 2]
        for day, counts in sorted(errors_by_day.items()):
            if counts[kind] >= max(20, 2 * median):
                spikes.append({"day": day, "kind": kind, "count": counts[kind], "median": median})

    google_total = sum(google_days.values())
    wasted = sum(waste.values())
    report = {
        "requests": total,
        "unparsed_lines": bad_lines,
        "crawlers": {k: {"hits": v, "unverified": bot_unverified.get(k, 0), "status": dict(bot_status[k]), "sections": dict(bot_sections[k].most_common())} for k, v in by_bot.most_common()},
        "googlebot": {"hits": google_total, "per_day": dict(sorted(google_days.items())), "top_urls": google_urls.most_common(args.top)},
        "crawl_waste": {"hits": wasted, "share_of_search_crawls": round(wasted / max(1, sum(by_bot[b] - bot_unverified.get(b, 0) for b in ("Googlebot", "Bingbot"))), 3), "by_reason": dict(waste.most_common()), "examples": {k: v.most_common(5) for k, v in waste_urls.items()}},
        "errors": {"top_404": error_urls[404].most_common(args.top), "top_5xx": error_urls[500].most_common(args.top), "spikes": spikes},
    }

    out = sys.stdout
    print(f"Requests analysed: {total} ({bad_lines} unparsed lines)", file=out)
    print("\nCrawlers (hits, unverified when --verify):", file=out)
    for k, v in by_bot.most_common():
        print(f"  {k:<16}{v:>8}  {bot_unverified.get(k, 0):>6} unverified  {dict(bot_status[k])}", file=out)
    print(f"\nGooglebot: {google_total} hits over {len(google_days)} days", file=out)
    for sec, n in bot_sections["Googlebot"].most_common():
        print(f"  {sec:<22}{n:>7}", file=out)
    print(f"\nCrawl waste (Googlebot + Bingbot): {wasted} hits, {report['crawl_waste']['share_of_search_crawls']:.0%} of their crawls", file=out)
    for reason, n in waste.most_common():
        ex = ", ".join(u for u, _ in waste_urls[reason].most_common(3))
        print(f"  {reason:<22}{n:>7}  e.g. {ex}", file=out)
    print("\nTop 404 URLs:", file=out)
    for u, n in error_urls[404].most_common(args.top):
        print(f"  {n:>6}  {u}", file=out)
    print("\nTop 5xx URLs:", file=out)
    for u, n in error_urls[500].most_common(args.top):
        print(f"  {n:>6}  {u}", file=out)
    print("\nError spikes:" + ("" if spikes else " none"), file=out)
    for s in spikes:
        print(f"  {s['day']}  {s['kind']} {s['count']} (median {s['median']})", file=out)

    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump(report, fh, indent=2)
    return 0


if __name__ == "__main__":
    sys.exit(main())
