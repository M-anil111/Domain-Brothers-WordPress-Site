#!/usr/bin/env python3
"""Turns design-audit.js results into a per-page checklist and a task list (Markdown).

    python3 tests/qa/design-audit-report.py <results.json> > docs/DESIGN_QA.md

Each check maps to one item of the 2026-11-22 design brief. A check is:
  pass  every measured width meets the rule
  fail  at least one width breaks it (the task list says which widths and what was measured)
  n/a   the element does not exist on that page
"""
import json
import sys
from collections import OrderedDict, defaultdict

rows = json.load(open(sys.argv[1]))
TOL = 2


def within(a, b, tol=TOL):
    return a is not None and b is not None and abs(a - b) <= tol


CHECKS = OrderedDict([
    ('hscroll', 'No horizontal scroll, clipping or overlap'),
    ('h1', 'Exactly one H1'),
    ('container', 'Content container 1320px with 15px gutters'),
    ('dbxwrap', '.dbx-wrap content 15px from each side on mobile'),
    ('padding', 'Section padding 80 / 60 / 45px (cards: gap below)'),
    ('doublegap', 'One gap between neighbouring sections'),
    ('tap', 'No tap-highlight border on mobile'),
    ('focus', 'Visible keyboard focus'),
    ('crumb', 'Breadcrumb on one line, domain name weight 700'),
    ('header', 'Mobile header: logo beside menu, no overlap (DB-11)'),
    ('buy', 'Buy Now single dark pill, equal button widths (DB-12)'),
    ('count', 'Availability count matches listings (DB-06, DB-14)'),
    ('a11y', 'Search fields labelled, footer tap targets 32px+ (DB-28)'),
    ('checkout', 'Order summary shows the listing price (DB-29)'),
    ('errors', 'Page loads without errors'),
])


def judge(r):
    """Returns {check: (ok: bool|None, detail)} for one page at one width."""
    out = {}
    w = r['width']
    if 'error' in r:
        return {'errors': (False, 'measurement failed: ' + r['error'][:80])}
    out['hscroll'] = (r.get('hscroll', 0) == 0, '%spx wider than the screen' % r.get('hscroll'))
    out['h1'] = (r.get('h1') == 1, '%s visible H1 elements%s' % (r.get('h1'), ('; hidden H1: "%s"' % '", "'.join(r.get('h1Hidden') or [])) if r.get('h1Hidden') else ''))
    if r.get('contentLeft') is not None:
        out['container'] = (within(r['contentLeft'], r['wantLeft']), 'content starts at %spx, expected %spx (measured on %s)' % (r['contentLeft'], r['wantLeft'], r.get('containerRef')))
    if r.get('dbxWrap') and w < 768:
        bad = [v for v in r['dbxWrap'] if not within(v, 15, 1)]
        out['dbxwrap'] = (not bad, '.dbx-wrap content at %spx, expected 15px' % (bad[0] if bad else 15))
    secs = r.get('sections') or []
    if secs:
        want = r.get('wantPad')
        bad = [s for s in secs if s.get('bad')]
        out['padding'] = (not bad, ', '.join(('%s card gap %spx' % (s['cls'], s['mb'])) if s['kind'] == 'K' else ('%s %s/%spx' % (s['cls'], s['top'], s['bottom'])) for s in bad[:3]) + ' (expected %spx)' % want)
    gaps = r.get('gaps') or []
    if gaps:
        dbl = [g for g in gaps if not g['ok']]
        out['doublegap'] = (not dbl, ', '.join('%s: %spx' % (g['between'], g['gap']) for g in dbl[:2]) + ' (expected one %spx gap)' % r.get('wantPad'))
    if w < 1024:
        out['tap'] = (r.get('tapHighlight') in ('rgba(0, 0, 0, 0)', 'transparent'), 'tap highlight %s' % r.get('tapHighlight'))
    if r.get('focusVisible') is not None:
        out['focus'] = (bool(r['focusVisible']), 'first Tab stop has no outline or shadow')
    if r.get('crumbLines') is not None:
        ok = (r['crumbLines'] == 1 or w < 375)
        detail = 'breadcrumb on %s lines ("%s")' % (r['crumbLines'], r.get('crumbText', ''))
        if r['page'] == 'Domain page' and (r.get('crumbLastWeight') or 0) < 700:
            ok = False
            detail += '; domain name weight %s' % r.get('crumbLastWeight')
        out['crumb'] = (ok, detail)
    if w < 1024 and r.get('logoBurgerOverlap') is not None:
        out['header'] = (not r['logoBurgerOverlap'], 'logo overlaps the menu button')
    if r['page'] in ('All domains', 'All domains, filtered link', 'Category archive', 'Deals', 'Find a domain') and r.get('buyDark') is not None:
        aw = r.get('actionWidths') or []
        eq = len(aw) < 2 or max(aw) - min(aw) <= TOL
        ok = r['buyDark'] and r['buyPill'] and not r.get('nestedPill') and eq
        out['buy'] = (ok, 'dark=%s pill=%s nested=%s widths=%s' % (r['buyDark'], r['buyPill'], r.get('nestedPill'), aw))
    if r['page'] in ('All domains', 'All domains, filtered link'):
        n, shown, live = r.get('countNum'), r.get('cardsShown'), r.get('liveCount')
        if r['page'] == 'All domains':
            ok = n is not None and n == shown and (live is None or abs(n - live) <= 1)
            out['count'] = (ok, 'count says %s, %s cards shown, index has %s' % (n, shown, live))
        else:
            f = r.get('filteredNum')
            out['count'] = (f is not None and f == shown, 'filtered link: filtered count says %s, %s cards shown (total line %s)' % (f, shown, n))
    out['a11y'] = (r.get('searchUnlabelled', 0) == 0 and (r.get('footerMinTap') or 99) >= 32, '%s unlabelled search fields, smallest footer link %spx' % (r.get('searchUnlabelled'), r.get('footerMinTap')))
    if r['page'] == 'Checkout':
        out['checkout'] = (r.get('summaryText') == '$10,125.00' or r.get('summaryText') == '$10,125', 'summary shows "%s", listing price $10,125' % r.get('summaryText'))
    expected_status = 404 if r['page'] == '404 page' else 200
    out['errors'] = (r.get('status') == expected_status and not r.get('errors'), 'HTTP %s; %s' % (r.get('status'), '; '.join(r.get('errors') or [])[:120] or 'no script errors'))
    return out


pages = OrderedDict()
for r in rows:
    pages.setdefault((r['page'], r['url']), []).append(r)

table = OrderedDict()
tasks = defaultdict(list)
for (page, url), rs in pages.items():
    table[page] = {}
    for key in CHECKS:
        fails, seen = [], False
        for r in rs:
            j = judge(r).get(key)
            if j is None:
                continue
            seen = True
            if not j[0]:
                fails.append((r['width'], j[1]))
        if not seen:
            table[page][key] = 'n/a'
        elif fails:
            table[page][key] = 'fail'
            widths = ', '.join(str(w) for w, _ in fails)
            tasks[key].append((page, url, widths, fails[0][1]))
        else:
            table[page][key] = 'pass'

widths = sorted({r['width'] for r in rows})
print('# Design QA: checklist and task list')
print()
print('Measured on https://beta.domainbrothers.com in Chromium at %d widths (%s px), %d pages, %d page-width combinations.' % (len(widths), ', '.join(map(str, widths)), len(pages), len(rows)))
print('Generated by `tests/qa/design-audit.js` and `tests/qa/design-audit-report.py`; re-run both after each fix.')
print()
total = sum(1 for p in table.values() for v in p.values() if v != 'n/a')
passed = sum(1 for p in table.values() for v in p.values() if v == 'pass')
print('**Result: %d of %d page checks pass.**' % (passed, total))
print()
print('## Checklist by page')
print()
icon = {'pass': '✅', 'fail': '❌', 'n/a': '—'}
keys = list(CHECKS)
print('| Page | ' + ' | '.join(str(i + 1) for i in range(len(keys))) + ' |')
print('|---|' + '---|' * len(keys))
for page, res in table.items():
    print('| %s | %s |' % (page, ' | '.join(icon[res[k]] for k in keys)))
print()
print('Checks: ' + '; '.join('**%d** %s' % (i + 1, CHECKS[k]) for i, k in enumerate(keys)) + '.')
print()
print('## Task list (failures to fix)')
print()
if not tasks:
    print('Nothing failed.')
for key in keys:
    if key not in tasks:
        continue
    print('### %s' % CHECKS[key])
    print()
    for page, url, ws, detail in tasks[key]:
        print('- [ ] **%s** (`%s`) at %s px: %s' % (page, url, ws, detail))
    print()
print('## Still to verify by hand')
print()
print('- [ ] Firefox (desktop and Android) on every page above')
print('- [ ] Safari on a physical iPhone: tap highlight, sticky header, filters after Back')
print('- [ ] Filters: restored after Back and reload, shared links, Clear Filters (DB-14), on a real device')
print('- [ ] Screen reader pass (VoiceOver, NVDA) on the header search, mobile menu and checkout')
