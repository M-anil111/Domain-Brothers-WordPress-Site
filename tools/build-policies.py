#!/usr/bin/env python3
"""Builds the policy pages from content-policies/src/*.md.

Each source file has front matter (title, slug, sub) and a body made of
"## Heading" sections, paragraphs, "- " lists, **bold**, [text](url) links and
one optional pipe table. The output is a self-contained wp:html block in the
site's existing "dbr-" layout, written to db-custom-blocks/policies/<slug>.html
with a manifest. The page ID is filled in at import time (token __ID__).

Run: python3 tools/build-policies.py
"""
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "content-policies" / "src"
OUT = ROOT / "db-custom-blocks" / "policies"
CSS = (ROOT / "content-policies" / "template.css").read_text(encoding="utf-8")
JS = (ROOT / "content-policies" / "template.js").read_text(encoding="utf-8")

EXTRA_CSS = """
#dbr .dbr-sec ul,#dbr .dbr-sec ol{margin:0 0 14px 0;padding-left:22px;list-style:disc}
#dbr .dbr-sec ol{list-style:decimal}
#dbr .dbr-sec li{font-size:17px;line-height:1.5;color:var(--text);margin:0 0 6px;padding:0;list-style:inherit;text-wrap:pretty}
#dbr .dbr-sec strong{color:var(--navy);font-weight:700}
#dbr .dbr-sec p a,#dbr .dbr-sec li a{color:var(--navy)!important;font-weight:600;border-bottom:2px solid var(--lime);padding-bottom:1px;overflow-wrap:anywhere}
#dbr .dbr-sec p a:hover,#dbr .dbr-sec li a:hover{color:var(--lime-ink)!important}
#dbr .dbr-tablewrap{overflow-x:auto;margin:0 0 16px;border:1px solid var(--line);border-radius:10px}
#dbr .dbr-sec table{border-collapse:collapse;width:100%;min-width:520px;font-size:16px;line-height:1.45}
#dbr .dbr-sec th,#dbr .dbr-sec td{text-align:left;vertical-align:top;padding:10px 14px;border-bottom:1px solid var(--line)}
#dbr .dbr-sec th{background:var(--tint);color:var(--navy);font-weight:700}
#dbr .dbr-sec tr:last-child td{border-bottom:0}
#dbr .dbr-meta{font-size:15px;color:var(--muted);margin:0 0 22px}
@media (max-width:640px){#dbr .dbr-sec li{font-size:16px}}
"""

MAIL = "sales@domainbrothers.com"


def parse(path: Path):
    text = path.read_text(encoding="utf-8")
    m = re.match(r"---\n(.*?)\n---\n(.*)\Z", text, re.S)
    if not m:
        sys.exit(f"{path.name}: missing front matter")
    meta = {}
    for line in m.group(1).splitlines():
        k, _, v = line.partition(":")
        meta[k.strip()] = v.strip()
    for key in ("title", "slug", "sub"):
        if not meta.get(key):
            sys.exit(f"{path.name}: front matter '{key}' is empty")
    return meta, m.group(2).strip("\n")


def inline(s: str) -> str:
    s = html.escape(s, quote=False)
    s = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", s)
    held = []

    def link(mo):
        label, url = mo.group(1), mo.group(2)
        if not re.match(r"^(/|https?://|mailto:)", url):
            sys.exit(f"unsafe link target: {url}")
        held.append(f'<a href="{html.escape(url, quote=True)}">{label}</a>')
        return f"\x00{len(held) - 1}\x00"

    # Markdown links are held back so the address auto-link never nests inside them.
    s = re.sub(r"\[([^\]]+)\]\(([^)\s]+)\)", link, s)
    s = re.sub(
        re.escape(MAIL),
        lambda m: f'<a class="dbr-mail" href="mailto:{m.group(0)}">{m.group(0)}</a>',
        s,
        flags=re.I,
    )
    return re.sub(r"\x00(\d+)\x00", lambda m: held[int(m.group(1))], s)


def slug_id(title: str, used: set) -> str:
    base = re.sub(r"^\d+\.\s*", "", title)
    base = re.sub(r"[^a-z0-9]+", "-", base.lower()).strip("-") or "section"
    cand, n = "dbr-" + base, 2
    while cand in used:
        cand = f"dbr-{base}-{n}"
        n += 1
    used.add(cand)
    return cand


def blocks(body: str):
    """Yields (heading, [lines]) sections."""
    cur_h, cur = None, []
    for line in body.splitlines():
        if line.startswith("## "):
            if cur_h is not None:
                yield cur_h, cur
            cur_h, cur = line[3:].strip(), []
        else:
            cur.append(line)
    if cur_h is not None:
        yield cur_h, cur


def render_lines(lines):
    out, para, items, kind, rows = [], [], [], None, []

    def flush_para():
        if para:
            out.append("<p>" + inline(" ".join(para)) + "</p>")
            para.clear()

    def flush_list():
        nonlocal kind
        if items:
            out.append(f"<{kind}>" + "".join(f"<li>{inline(i)}</li>" for i in items) + f"</{kind}>")
            items.clear()
            kind = None

    def flush_table():
        if rows:
            head, body = rows[0], rows[2:] if len(rows) > 1 and set(rows[1][0]) <= set("-: ") else rows[1:]
            t = "<table><thead><tr>" + "".join(f"<th>{inline(c)}</th>" for c in head) + "</tr></thead><tbody>"
            for r in body:
                t += "<tr>" + "".join(f"<td>{inline(c)}</td>" for c in r) + "</tr>"
            out.append('<div class="dbr-tablewrap">' + t + "</tbody></table></div>")
            rows.clear()

    for raw in lines:
        line = raw.rstrip()
        if not line.strip():
            flush_para(); flush_list(); flush_table()
            continue
        if line.startswith("|"):
            flush_para(); flush_list()
            rows.append([c.strip() for c in line.strip().strip("|").split("|")])
            continue
        flush_table()
        m = re.match(r"^(-|\d+\.)\s+(.*)$", line)
        if m:
            flush_para()
            k = "ul" if m.group(1) == "-" else "ol"
            if kind and kind != k:
                flush_list()
            kind = k
            items.append(m.group(2))
        else:
            flush_list()
            para.append(line.strip())
    flush_para(); flush_list(); flush_table()
    return "\n".join(out)


def build(path: Path):
    meta, body = parse(path)
    used, secs = set(), []
    for h, lines in blocks(body):
        secs.append((slug_id(h, used), h, render_lines(lines)))
    if not secs:
        sys.exit(f"{path.name}: no sections")
    toc_items = []
    for n, (i, h, _) in enumerate(secs):
        label = html.escape(re.sub(r"^\d+\.\s*", "", h))
        cls = ' class="is-active"' if n == 0 else ""
        toc_items.append('<li><a href="#' + i + '"' + cls + '>' + label + '</a></li>')
    toc = "\n".join(toc_items)
    sections = "\n".join(
        f'<div class="dbr-sec" id="{i}"><h2>{html.escape(h)}</h2>\n{b}</div>' for i, h, b in secs
    )
    title, sub = html.escape(meta["title"]), html.escape(meta["sub"])
    css = CSS.replace("body.page-id-1887", "body.page-id-__ID__").replace("post-1887", "post-__ID__")
    page = f"""<!-- wp:html -->
<style>
{css}
{EXTRA_CSS}
</style>
<div id="dbr">
<section class="dbr-hero dbr-bleed"><div class="dbr-wrap">
<ol class="dbr-crumbs" aria-label="Breadcrumb"><li><a href="/">Home</a></li><li><span aria-current="page">{title}</span></li></ol>
<div class="dbr-title"><h1 class="dbr-h1">{title}</h1>
<p class="dbr-sub">{sub}</p></div>
</div></section>
<section class="dbr-body dbr-bleed"><div class="dbr-wrap dbr-grid">
<aside class="dbr-aside" aria-label="On this page"><div class="dbr-toc" id="dbr-toc">
<p class="dbr-toc-title">On this page</p>
<ul>
{toc}
</ul>
<a class="dbr-top" href="#page">Back to top <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 13.5V2.5M3.5 7 8 2.5 12.5 7"/></svg></a>
</div></aside>
<div class="dbr-content">
{sections}
</div>
</div></section>
</div>
<script>{JS}</script>
<!-- /wp:html -->
"""
    return meta, page


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    manifest = []
    for path in sorted(SRC.glob("*.md")):
        meta, page = build(path)
        (OUT / f"{meta['slug']}.html").write_text(page, encoding="utf-8")
        manifest.append({"slug": meta["slug"], "title": meta["title"], "bytes": len(page.encode("utf-8"))})
    (OUT / "manifest.json").write_text(json.dumps(manifest, indent=1) + "\n", encoding="utf-8")
    for m in manifest:
        print(f"{m['slug']:<36} {m['bytes']:>7} bytes")


if __name__ == "__main__":
    main()
