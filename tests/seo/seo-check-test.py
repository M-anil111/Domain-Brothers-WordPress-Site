#!/usr/bin/env python3
"""Offline tests for tools/seo-check.py (no network).

    python3 tests/seo/seo-check-test.py
"""
import importlib.util
import pathlib
import sys

spec = importlib.util.spec_from_file_location("seo_check", pathlib.Path(__file__).resolve().parents[2] / "tools" / "seo-check.py")
sc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sc)

passed = failed = 0


def check(name, ok):
    global passed, failed
    if ok:
        passed += 1
        print("PASS", name)
    else:
        failed += 1
        print("FAIL", name)


U = "https://example.test/a/"
good = ('<html><head><title>A page</title><meta name="description" content="About A.">'
        '<link rel="canonical" href="https://example.test/a/">'
        '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","@id":"https://example.test/#organization"},'
        '{"@type":"BreadcrumbList","itemListElement":[{"position":"1"},{"position":2}]}]}</script></head><body><h1>A</h1></body></html>')
e, w, c = sc.check_page(U, 200, {}, good, "production", True)
check("a clean production page passes", e == [] and w == [] and c == U)

e, _, _ = sc.check_page(U, 200, {}, good, "staging", False)
check("staging page without noindex is an error", any("staging" in x for x in e))
e, _, _ = sc.check_page(U, 200, {"x-robots-tag": "noindex, nofollow"}, good, "staging", False)
check("staging noindex via header passes", e == [])

noidx = good.replace("<title>", '<meta name="robots" content="noindex, follow"><title>')
e, _, _ = sc.check_page(U, 200, {}, noidx, "production", True)
check("noindex on a production sitemap page is an error", any("noindex" in x for x in e))
e, _, _ = sc.check_page(U, 200, {}, noidx, "production", False)
check("noindex outside the sitemap is allowed", not any("noindex" in x for x in e))

e, _, _ = sc.check_page(U, 200, {}, good.replace('href="https://example.test/a/"', 'href="/a/"'), "production", True)
check("relative canonical is an error", any("relative canonical" in x for x in e))
e, _, _ = sc.check_page(U, 200, {}, good.replace('href="https://example.test/a/"', 'href="https://example.test/a/?p=1"'), "production", True)
check("canonical with a query string is an error", any("query string" in x for x in e))
e, _, _ = sc.check_page(U, 200, {}, good.replace('<link rel="canonical" href="https://example.test/a/">', ''), "production", True)
check("missing canonical is an error", "missing canonical" in e)
e, _, _ = sc.check_page(U, 200, {}, good.replace("<title>A page</title>", ""), "production", True)
check("missing title is an error", "missing <title>" in e)
e, _, _ = sc.check_page(U, 200, {}, good.replace("<title>A page</title>", "<title>A</title><title>B</title>"), "production", True)
check("duplicate title is an error", any("<title> elements" in x for x in e))
e, _, _ = sc.check_page(U, 200, {}, good.replace("<title>A page</title>", "<svg><title>icon</title></svg><title>A page</title>"), "production", True)
check("an SVG <title> is not a page title", e == [])
e, _, _ = sc.check_page(U, 301, {"location": "https://example.test/b/"}, "", "production", True)
check("a redirect is reported with its target", e == ["status 301 -> https://example.test/b/"])

_, w, _ = sc.check_page(U, 200, {}, good.replace("<h1>A</h1>", "<h1>A</h1><h1>B</h1>").replace('<meta name="description" content="About A.">', ""), "production", True)
check("two h1 and no description are warnings", any("h1" in x for x in w) and "missing meta description" in w)

check("invalid JSON-LD is reported", any("does not parse" in x for x in sc.check_jsonld(["{bad"])))
check("relative @id is reported", any("relative @id" in x for x in sc.check_jsonld(['{"@type":"WebPage","@id":"#webpage"}'])))
check("breadcrumb positions must be 1..n", any("positions" in x for x in sc.check_jsonld(['{"@type":"BreadcrumbList","itemListElement":[{"position":1},{"position":3}]}'])))
check("Product offer needs price and currency", len(sc.check_jsonld(['{"@type":"Product","offers":{"@type":"Offer"}}'])) == 2)
check("a valid Product passes", sc.check_jsonld(['{"@type":"Product","offers":{"@type":"Offer","price":"10.00","priceCurrency":"USD"}}']) == [])

check("production robots.txt with sitemap passes", sc.check_robots("User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://example.test/sitemap_index.xml\n", "production") == [])
check("production robots.txt that blocks everything fails", any("whole site" in x for x in sc.check_robots("User-agent: *\nDisallow: /\n\nSitemap: https://e.test/s.xml", "production")))
check("a bot-specific Disallow: / is fine on production", sc.check_robots("User-agent: Bytespider\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://e.test/s.xml", "production") == [])
check("staging robots.txt must block everything", sc.check_robots("User-agent: *\nDisallow: /\n", "staging") == [] and sc.check_robots("User-agent: *\nDisallow:\n", "staging") != [])

idx = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://e.test/a.xml</loc></sitemap></sitemapindex>'
us = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://e.test/x/</loc></url></urlset>'
check("sitemap index and urlset are read", sc.sitemap_locs(idx) == (["https://e.test/a.xml"], []) and sc.sitemap_locs(us) == ([], ["https://e.test/x/"]))

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
