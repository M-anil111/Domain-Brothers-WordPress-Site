#!/usr/bin/env python3
"""Full-site crawl audit for staging (sequential; the host returns 508 under load).

Usage: BETA_BASIC=user:pass python3 tests/qa/site-crawl.py paths.txt out.json > out.txt
paths.txt: one path per line (e.g. from wp-sitemap.xml). Per page it checks
status, one <title>, title 25-65, description 70-160, one <h1>, JSON-LD parses,
img alt, duplicate ids, target=_blank rel, http:// links, mixed content,
viewport, lang and canonical, and records title/description/internal links so
duplicates and broken links can be found afterwards.
"""
import sys,re,json,subprocess,html,os,time
from html.parser import HTMLParser
BASE="https://beta.domainbrothers.com"; AUTH=os.environ['BETA_BASIC']
class P(HTMLParser):
    def __init__(s):
        super().__init__(); s.titles=0; s.h1=0; s.ids={}; s.imgs_noalt=[]; s.blank_unsafe=[]; s.http_links=[]; s.mixed=[]
        s.jsonld=[]; s.inld=False; s.buf=''; s.desc=None; s.canon=None; s.robots=[]; s.viewport=False; s.lang=None; s.intitle=False; s.title=''
        s.dupids=set(); s.links=set()
    def handle_starttag(s,t,a):
        a=dict(a)
        if t=='html': s.lang=a.get('lang')
        if t=='title': s.titles+=1; s.intitle=True
        if t=='h1': s.h1+=1
        i=a.get('id')
        if i:
            if i in s.ids: s.dupids.add(i)
            s.ids[i]=1
        if t=='img' and a.get('alt') is None: s.imgs_noalt.append(a.get('src','')[:80])
        if t=='a':
            h=a.get('href') or ''
            if (a.get('target') or '').lower()=='_blank':
                rel=(a.get('rel') or '').lower()
                if 'noopener' not in rel and 'noreferrer' not in rel: s.blank_unsafe.append(h[:80])
            if h.startswith('http://'): s.http_links.append(h[:80])
            if h.startswith('https://beta.domainbrothers.com/') or (h.startswith('/') and not h.startswith('//')): s.links.add(h.replace('https://beta.domainbrothers.com','').split('#')[0])
        for k in ('src',):
            v=a.get(k) or ''
            if v.startswith('http://') and t in ('img','script','iframe','source','video','audio'): s.mixed.append(v[:80])
        if t=='link' and (a.get('href') or '').startswith('http://') and 'stylesheet' in (a.get('rel') or ''): s.mixed.append(a['href'][:80])
        if t=='meta':
            n=(a.get('name') or '').lower()
            if n=='description': s.desc=a.get('content','')
            if n=='robots': s.robots.append(a.get('content',''))
            if n=='viewport': s.viewport=True
        if t=='link' and (a.get('rel') or '').lower()=='canonical': s.canon=a.get('href')
        if t=='script' and (a.get('type') or '').lower()=='application/ld+json': s.inld=True; s.buf=''
    def handle_endtag(s,t):
        if t=='script' and s.inld: s.jsonld.append(s.buf); s.inld=False
        if t=='title': s.intitle=False
    def handle_data(s,d):
        if s.inld: s.buf+=d
        if s.intitle: s.title+=d
def fetch(path):
    t=time.time()
    r=subprocess.run(['curl','-sS','-m','60','-u',AUTH,'-o','/dev/stdout','-w','\n__CODE__%{http_code} %{url_effective}','-L',BASE+path],capture_output=True,text=True)
    body,_,tail=r.stdout.rpartition('\n__CODE__'); code,_,eff=tail.partition(' ')
    return body,code,eff,time.time()-t
res=[]
for path in [l.strip() for l in open(sys.argv[1]) if l.strip()]:
    body,code,eff,dt=fetch(path)
    p=P()
    try: p.feed(body)
    except Exception as e: pass
    bad_ld=0
    for b in p.jsonld:
        try: json.loads(b)
        except Exception: bad_ld+=1
    title=html.unescape(p.title.strip()); d=html.unescape(p.desc or '')
    f=[]
    if p.titles!=1: f.append(f'exactly_one_title({p.titles})')
    if not 25<=len(title)<=65: f.append(f'title_25_65({len(title)}:{title[:70]})')
    if not 70<=len(d)<=160: f.append(f'description_70_160({len(d)})')
    if p.h1!=1: f.append(f'exactly_one_h1({p.h1})')
    if bad_ld or not p.jsonld: f.append(f'jsonld_valid(bad={bad_ld},n={len(p.jsonld)})')
    if p.imgs_noalt: f.append(f'alt({len(p.imgs_noalt)}:{p.imgs_noalt[:2]})')
    if p.dupids: f.append(f'dup_ids({sorted(p.dupids)[:5]})')
    if p.blank_unsafe: f.append(f'blank_unsafe({len(p.blank_unsafe)}:{p.blank_unsafe[:2]})')
    if p.http_links: f.append(f'http_links({len(p.http_links)}:{p.http_links[:2]})')
    if p.mixed: f.append(f'mixed({p.mixed[:2]})')
    if not p.viewport: f.append('viewport')
    if not p.lang: f.append('lang')
    if p.canon and p.canon.replace('https://beta.domainbrothers.com','')!=eff.replace('https://beta.domainbrothers.com',''): f.append(f'canonical({p.canon} vs {eff})')
    if not p.canon: f.append('canonical(missing)')
    res.append({'title':title,'desc':d,'links':sorted(p.links),'path':path,'code':code,'eff':eff,'t':round(dt,2),'fails':f,'robots':p.robots})
    if len(res)%25==0: json.dump(res,open(sys.argv[2],'w'))
    print(code,f'{dt:.1f}s',path,'|',' ; '.join(f) or 'OK','| robots=',p.robots,flush=True)
json.dump(res,open(sys.argv[2],'w'),indent=1)
