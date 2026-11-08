#!/usr/bin/env python3
"""Builds the content cluster for the Domain Brothers site.

Reads content-cluster/src/*.md (a small authoring format, see below) and writes
block-editor (Gutenberg) markup plus a manifest into db-custom-blocks/cluster/.
The plugin's importer (db-cluster-block.php) turns those files into posts.

Authoring format
  front matter   title, slug, role (pillar|spoke), excerpt, tags
  ## / ###       headings (h2 gets an anchor id for the table of contents)
  text           paragraphs; [text](/internal/) [text](https://external) **bold** `code`
  - item / 1. x  lists;  | a | b |  tables (second line is the separator)
  !img:key|alt|caption          image from db-custom-blocks/assets/cluster/<key>.(png|jpg)
  !takeaways Title              box; the following "- " lines are its list
  !callout Title|text           highlighted note
  !toc                          table of contents from the h2 headings
  !details:Question|Answer      collapsible answer (consecutive lines form a group)
  !cta Title|text|Label1|/url1|Label2|/url2   call to action with buttons
  !related                      "keep reading" links to the rest of the cluster
"""
import html, json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'content-cluster', 'src')
OUT = os.path.join(ROOT, 'db-custom-blocks', 'cluster')
IMG = os.path.join(ROOT, 'db-custom-blocks', 'assets', 'cluster')


def slugify(t):
    return re.sub(r'[^a-z0-9]+', '-', t.lower()).strip('-')


def inline(text):
    text = html.escape(text, quote=False)
    text = re.sub(r'`([^`]+)`', r'<code>\1</code>', text)
    text = re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', text)

    def link(m):
        label, url = m.group(1), m.group(2)
        if url.startswith('/'):
            return '<a href="__HOME__%s">%s</a>' % (url, label)
        return '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>' % (url, label)
    return re.sub(r'\[([^\]]+)\]\(([^)\s]+)\)', link, text)


def attr(s):
    return html.escape(s, quote=True)


def block(name, inner, attrs=None):
    a = (' ' + json.dumps(attrs, separators=(',', ':'))) if attrs else ''
    return '<!-- wp:%s%s -->\n%s\n<!-- /wp:%s -->' % (name, a, inner, name)


def para(t):
    return block('paragraph', '<p>%s</p>' % inline(t))


def heading(level, t, anchor=None):
    idattr = ' id="%s"' % anchor if anchor else ''
    return block('heading', '<h%d class="wp-block-heading"%s>%s</h%d>' % (level, idattr, inline(t), level), {'level': level} if level != 2 else None)


def lst(items, ordered=False):
    tag = 'ol' if ordered else 'ul'
    inner = ''.join(block('list-item', '<li>%s</li>' % inline(i)) for i in items)
    return block('list', '<%s class="wp-block-list">%s</%s>' % (tag, inner, tag), {'ordered': True} if ordered else None)


def table(rows):
    head = rows[0]
    body = rows[2:]
    th = ''.join('<th>%s</th>' % inline(c) for c in head)
    trs = ''.join('<tr>%s</tr>' % ''.join('<td>%s</td>' % inline(c) for c in r) for r in body)
    return block('table', '<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr>%s</tr></thead><tbody>%s</tbody></table></figure>' % (th, trs))


def image(key, alt, cap):
    fig = '<figure class="wp-block-image size-large"><img src="__IMGURL:%s__" alt="%s" class="wp-image-__IMGID:%s__"/>%s</figure>' % (
        key, attr(alt), key, ('<figcaption class="wp-element-caption">%s</figcaption>' % inline(cap)) if cap else '')
    return block('image', fig, {'id': '__IMGID:%s__' % key, 'sizeSlug': 'large', 'linkDestination': 'none'}).replace('"__IMGID:%s__"' % key, '__IMGID:%s__' % key)


def group(cls, inner):
    return block('group', '<div class="wp-block-group %s">\n%s\n</div>' % (cls, inner), {'className': cls, 'layout': {'type': 'constrained'}})


def button(label, url):
    href = ('__HOME__' + url) if url.startswith('/') else url
    return block('button', '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>' % (attr(href), html.escape(label)))


def buttons(pairs):
    return block('buttons', '<div class="wp-block-buttons">\n%s\n</div>' % '\n'.join(button(l, u) for l, u in pairs))


def details(q, a):
    return block('details', '<details class="wp-block-details"><summary>%s</summary>\n%s</details>' % (html.escape(q), para(a)))


def parse_front(text):
    m = re.match(r'---\n(.*?)\n---\n', text, re.S)
    fm = {}
    for line in m.group(1).splitlines():
        k, v = line.split(':', 1)
        fm[k.strip()] = v.strip()
    return fm, text[m.end():]


def build():
    os.makedirs(OUT, exist_ok=True)
    arts = []
    for fn in sorted(os.listdir(SRC)):
        if fn.endswith('.md'):
            fm, body = parse_front(open(os.path.join(SRC, fn), encoding='utf-8').read())
            fm['body'] = body
            arts.append(fm)
    arts.sort(key=lambda a: (a['role'] != 'pillar', a['slug']))
    pillar = [a for a in arts if a['role'] == 'pillar'][0]
    spokes = [a for a in arts if a['role'] != 'pillar']
    alts_all = {}
    manifest = {'category': {'slug': 'domain-guides', 'name': 'Domain Guides', 'description': 'Guides to premium domain names, websites and marketing from the Domain Brothers team.'}, 'images': {}, 'articles': []}
    stats = {}
    for a in arts:
        lines = a['body'].splitlines()
        out, h2s, used_imgs = [], [], []
        alts_this = {}
        i = 0
        words = 0
        while i < len(lines):
            ln = lines[i].rstrip()
            if not ln.strip():
                i += 1
                continue
            if ln.startswith('### '):
                out.append(heading(3, ln[4:]))
            elif ln.startswith('## '):
                t = ln[3:]
                h2s.append(t)
                out.append(heading(2, t, slugify(t)))
            elif ln.startswith('!toc'):
                out.append('@@TOC@@')
            elif ln.startswith('!takeaways'):
                title = ln[len('!takeaways'):].strip() or 'Key takeaways'
                items = []
                i += 1
                while i < len(lines) and lines[i].startswith('- '):
                    items.append(lines[i][2:]); i += 1
                out.append(group('db-takeaways', heading(2, title, slugify(title) + '-' + a['slug'][:6]).replace('<h2 ', '<h2 ') + '\n' + lst(items)))
                words += sum(len(x.split()) for x in items)
                continue
            elif ln.startswith('!callout'):
                t, x = ln[len('!callout'):].strip().split('|', 1)
                out.append(group('db-callout', heading(3, t) + '\n' + para(x)))
            elif ln.startswith('!img:'):
                key, alt, cap = (ln[5:].split('|') + ['', ''])[:3]
                used_imgs.append(key)
                alts_this.setdefault(key, alt)
                out.append(image(key, alt, cap))
            elif ln.startswith('!details:'):
                grp = []
                while i < len(lines) and lines[i].startswith('!details:'):
                    q, x = lines[i][9:].split('|', 1)
                    grp.append(details(q, x)); words += len(x.split()); i += 1
                out.append('\n'.join(grp))
                continue
            elif ln.startswith('!cta'):
                parts = ln[4:].strip().split('|')
                title, text = parts[0], parts[1]
                pairs = list(zip(parts[2::2], parts[3::2]))
                out.append(group('db-cta', heading(2, title) + '\n' + para(text) + '\n' + buttons(pairs)))
            elif ln.startswith('!related'):
                out.append('@@RELATED@@')
            elif ln.startswith('|'):
                rows = []
                while i < len(lines) and lines[i].startswith('|'):
                    rows.append([c.strip() for c in lines[i].strip().strip('|').split('|')]); i += 1
                out.append(table(rows))
                words += sum(len(c.split()) for r in rows for c in r)
                continue
            elif re.match(r'- ', ln):
                items = []
                while i < len(lines) and lines[i].startswith('- '):
                    items.append(lines[i][2:]); i += 1
                out.append(lst(items)); words += sum(len(x.split()) for x in items)
                continue
            elif re.match(r'\d+\. ', ln):
                items = []
                while i < len(lines) and re.match(r'\d+\. ', lines[i]):
                    items.append(re.sub(r'^\d+\. ', '', lines[i])); i += 1
                out.append(lst(items, True)); words += sum(len(x.split()) for x in items)
                continue
            else:
                buf = [ln]
                i += 1
                while i < len(lines) and lines[i].strip() and not re.match(r'(#|!|\||- |\d+\. )', lines[i]):
                    buf.append(lines[i].rstrip()); i += 1
                text = ' '.join(buf)
                out.append(para(text)); words += len(text.split())
                continue
            i += 1
        toc = group('db-toc', heading(2, 'In this guide', 'in-this-guide') + '\n' + lst(['[%s](#%s)' % (t, slugify(t)) for t in h2s if t not in ('Frequently asked questions',) or True]).replace('href="__HOME__#', 'href="#'))
        if a['role'] == 'pillar':
            rel_items = ['[%s](/%s/)' % (s2['title'].split(':')[0], s2['slug']) for s2 in spokes]
        else:
            idx = spokes.index(a)
            sib = [spokes[(idx + k) % len(spokes)] for k in (1, 2, 3)]
            rel_items = ['[%s](/%s/): %s' % (pillar['title'].split(':')[0], pillar['slug'], 'the complete guide')] + ['[%s](/%s/)' % (s2['title'].split(':')[0], s2['slug']) for s2 in sib]
        related = group('db-related', heading(2, 'Keep reading', 'keep-reading-' + a['slug'][:6]) + '\n' + lst(rel_items))
        content = '\n\n'.join(out).replace('@@TOC@@', toc).replace('@@RELATED@@', related)
        fn_out = a['slug'] + '.html'
        open(os.path.join(OUT, fn_out), 'w', encoding='utf-8').write(content + '\n')
        for k in used_imgs:
            manifest['images'].setdefault(k, None)
        alts_all.update(alts_this)
        manifest['articles'].append({
            'slug': a['slug'], 'title': a['title'], 'role': a['role'], 'excerpt': a['excerpt'],
            'tags': [t.strip() for t in a['tags'].split(',')], 'file': fn_out, 'featured': a['slug'],
            'images': sorted(set(used_imgs)), 'words': words,
        })
        stats[a['slug']] = words
    # images: everything in the assets folder
    titles = {a['slug']: a['title'] for a in arts}
    imgs = {}
    for fnm in sorted(os.listdir(IMG)):
        key, ext = os.path.splitext(fnm)
        alt = alts_all.get(key) or ('Cover image for: ' + titles[key] if key in titles else key)
        imgs[key] = {'file': fnm, 'alt': alt, 'title': titles.get(key, alts_all.get(key, key))[:90]}
    manifest['images'] = imgs
    json.dump(manifest, open(os.path.join(OUT, 'manifest.json'), 'w', encoding='utf-8'), indent=1, ensure_ascii=False)
    for k, v in stats.items():
        print('%5d words  %s' % (v, k))
    print('total', sum(stats.values()))


if __name__ == '__main__':
    build()
