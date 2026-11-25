#!/usr/bin/env python3
"""
Internal link audit (docs/INTERNAL_LINKING.md).

    python3 tools/link-audit.py crawl  [BASE_URL] [OUT.json]   # one page at a time (host limit)
    python3 tools/link-audit.py report OUT.json

crawl: reads the XML sitemaps, fetches every URL in them plus up to EXTRA (env, default 200)
pages they link to, and records each link: target, anchor text, rel, and the page region
(header, footer, breadcrumb or body). report: inlinks per page type, pages with no inlinks,
pages unreachable from home, click depth, anchors per key page, generic anchors, links to
redirects, outbound hosts and rel values. Standard library only.
"""
import sys
if __name__ == '__main__' and (len(sys.argv) < 2 or sys.argv[1] not in ('crawl', 'report')):
    print(__doc__); sys.exit(2)
MODE = sys.argv.pop(1)
if MODE == 'crawl':
    import re, json, time, sys, os, urllib.request, urllib.parse, collections
    from html.parser import HTMLParser
    BASE=(sys.argv[1] if len(sys.argv)>1 and sys.argv[1].startswith('http') else 'https://beta.domainbrothers.com').rstrip('/')
    UA='Mozilla/5.0 (DB link audit)'
    def get(u, tries=4):
        for i in range(tries):
            try:
                req=urllib.request.Request(u, headers={'User-Agent':UA,'Accept':'text/html,application/xml'})
                class NR(urllib.request.HTTPRedirectHandler):
                    def redirect_request(self,*a,**k): return None
                op=urllib.request.build_opener(NR)
                r=op.open(req, timeout=40); return r.status, dict(r.headers), r.read().decode('utf-8','replace')
            except urllib.error.HTTPError as e:
                if e.code==508 and i<tries-1: time.sleep(15); continue
                return e.code, dict(e.headers or {}), ''
            except Exception as e:
                time.sleep(4)
        return 0,{}, ''
    def sitemap_urls():
        out=[]; seen=set(); q=[BASE+'/sitemap_index.xml', BASE+'/db-pages-sitemap.xml', BASE+'/domain-tools-sitemap.xml']
        while q:
            u=q.pop(0)
            if u in seen: continue
            seen.add(u); st,h,b=get(u)
            locs=re.findall(r'<loc>\s*([^<\s]+)\s*</loc>', b)
            for l in locs:
                l=l.replace('&amp;','&')
                if l.endswith('.xml'): q.append(l)
                else: out.append(l)
        return list(dict.fromkeys(out)), sorted(seen)
    class P(HTMLParser):
        def __init__(s):
            super().__init__(); s.links=[]; s.stack=[]; s.cur=None; s.title=''; s.intitle=False; s.canon=''; s.robots=''; s.h1=[]; s.inh1=False; s.words=0
        def region(s):
            for t,a in reversed(s.stack):
                cls=(a.get('class') or '')+' '+(a.get('id') or '')
                if t in('header',) or re.search(r'\b(site-header|db-hd|masthead|db-mnav)\b',cls): return 'header'
                if t=='footer' or re.search(r'\b(colophon|db-ft|site-footer)\b',cls): return 'footer'
                if t=='nav' and re.search(r'breadcrumb',cls+(a.get('aria-label') or '').lower()): return 'crumb'
            return 'body'
        def handle_starttag(s,t,attrs):
            a=dict(attrs)
            if t in('br','img','meta','link','input','hr','source'):
                if t=='link' and a.get('rel')=='canonical': s.canon=a.get('href','')
                if t=='meta' and (a.get('name') or '').lower()=='robots': s.robots=a.get('content','')
                return
            s.stack.append((t,a))
            if t=='a' and a.get('href'): s.cur={'href':a['href'],'rel':a.get('rel',''),'text':'','region':s.region(),'target':a.get('target','')}
            if t=='title': s.intitle=True
            if t=='h1': s.inh1=True; s.h1.append('')
        def handle_endtag(s,t):
            if t=='a' and s.cur: s.cur['text']=re.sub(r'\s+',' ',s.cur['text']).strip()[:80]; s.links.append(s.cur); s.cur=None
            if t=='title': s.intitle=False
            if t=='h1': s.inh1=False
            for i in range(len(s.stack)-1,-1,-1):
                if s.stack[i][0]==t: del s.stack[i:]; break
        def handle_data(s,d):
            if s.cur is not None: s.cur['text']+=d
            if s.intitle: s.title+=d
            if s.inh1 and s.h1: s.h1[-1]+=d
            if s.region()=='body': s.words+=len(d.split())
    def norm(u):
        p=urllib.parse.urlsplit(u)
        path=p.path or '/'
        return p.scheme+'://'+p.netloc+path+(('?'+p.query) if p.query else '')
    urls, maps = sitemap_urls()
    print('sitemaps',len(maps),'urls',len(urls), flush=True)
    pages={}
    queue=list(urls); seen=set(urls)
    crawl_extra = int(os.environ.get('EXTRA','200'))
    extra=0
    i=0
    while queue:
        u=queue.pop(0); i+=1
        st,h,b=get(u)
        rec={'status':st,'loc':h.get('Location') or h.get('location',''),'insitemap':u in urls}
        if st==200 and 'html' in (h.get('Content-Type') or h.get('content-type','')):
            p=P(); 
            try: p.feed(b)
            except Exception: pass
            rec.update(title=p.title.strip(), canon=p.canon, robots=p.robots, h1=[x.strip() for x in p.h1], words=p.words, xrobots=h.get('X-Robots-Tag') or h.get('x-robots-tag',''))
            links=[]
            for l in p.links:
                href=urllib.parse.urljoin(u,l['href'])
                if not href.startswith('http'): continue
                hp=urllib.parse.urlsplit(href)
                internal = hp.netloc in (urllib.parse.urlsplit(BASE).netloc,'beta.domainbrothers.com','www.domainbrothers.com','domainbrothers.com')
                n=norm(href.split('#')[0]) if internal else href
                links.append({'to':n,'int':internal,'rel':l['rel'],'text':l['text'],'reg':l['region'],'tgt':l['target']})
                if internal and n.startswith(BASE) and '?' not in n and n not in seen and extra<crawl_extra and not re.search(r'/(wp-|feed|cdn-cgi)|\.(xml|jpg|png|webp|pdf|zip)$',n):
                    seen.add(n); queue.append(n); extra+=1
            rec['links']=links
        pages[u]=rec
        if i%25==0: print(i,len(queue),flush=True)
        time.sleep(2.0)
    OUT=sys.argv[2] if len(sys.argv)>2 else 'link-audit.json'
    json.dump({'base':BASE,'sitemaps':maps,'sitemap_urls':urls,'pages':pages}, open(OUT,'w'))
    print('done',len(pages),'->',OUT)

else:
    import json, collections, re, sys
    d=json.load(open(sys.argv[1])); P=d['pages']; B=d.get('base','https://beta.domainbrothers.com')
    sm=set(d['sitemap_urls'])
    ok={u:r for u,r in P.items() if r['status']==200 and 'links' in r}
    st=collections.Counter(r['status'] for r in P.values())
    print('status',dict(st))
    def path(u): return u.replace(B,'') or '/'
    inl=collections.defaultdict(set); inl_body=collections.defaultdict(set); anchors=collections.defaultdict(collections.Counter)
    ext=collections.Counter(); extrel=collections.Counter(); generic=collections.Counter(); broken=[]
    for u,r in ok.items():
        for l in r['links']:
            if l['int'] and l['to'].startswith(B):
                t=l['to'].split('?')[0]
                if t==u: continue
                inl[t].add(u)
                if l['reg']=='body': inl_body[t].add(u); anchors[t][l['text'].lower()]+=1
                if re.fullmatch(r'(read more|learn more|click here|here|more|view|details|view details|see more|continue reading)',l['text'].strip().lower()): generic[l['text'].strip().lower()]+=1
            elif not l['int']:
                host=re.sub(r'^https?://([^/]+).*',r'\1',l['to']); ext[host]+=1; extrel[(l['rel'] or '-')]+=1
    # depth BFS from home via links
    from collections import deque
    depth={B+'/':0}; q=deque([B+'/'])
    while q:
        u=q.popleft()
        for l in ok.get(u,{}).get('links',[]):
            t=l['to'].split('?')[0]
            if l['int'] and t in ok and t not in depth: depth[t]=depth[u]+1; q.append(t)
    def kind(p):
        if p.startswith('/domains/'): return 'domain'
        if p.startswith('/domain-tools/'): return 'tool'
        if p.startswith('/domain-category/'): return 'dcat'
        if p.startswith('/category/'): return 'blogcat'
        return 'page/post'
    idx=[u for u,r in ok.items() if 'noindex' not in (r.get('robots','')+r.get('xrobots','')).lower() or True]
    print('pages ok',len(ok),'in sitemap',len(sm))
    byk=collections.defaultdict(list)
    for u in ok: byk[kind(path(u))].append(u)
    for k,us in byk.items():
        a=[len(inl[u]) for u in us]; b=[len(inl_body[u]) for u in us]; dp=[depth.get(u,99) for u in us]
        print(f"{k:10} n={len(us):3} inlinks avg={sum(a)/len(a):6.1f} min={min(a):3}  body-inlinks avg={sum(b)/len(b):5.1f} zero_body={sum(1 for x in b if x==0):3}  depth max={max(dp)} >3={sum(1 for x in dp if x>3)} unreachable={sum(1 for x in dp if x==99)}")
    print('\nORPHANS in sitemap (no inlinks at all):', [path(u) for u in sm if u in ok and not inl[u]][:30])
    print('\nUnreachable from home:', [path(u) for u in ok if u not in depth][:30])
    print('\nSitemap URLs not 200:', [(path(u),P[u]['status'],P[u].get('loc','')[:60]) for u in sm if u in P and P[u]['status']!=200][:30])
    print('\nKey pages: inlinks(all/body) depth, top anchors')
    for p in ['/','/all-domains/','/domain-brokerage-services/','/acquire-premium-domain/','/sell-your-domains/','/payment-terms/','/domain-appraisal-services/','/buy-domains-service/','/4-character-domains/','/5-character-domains/','/3-character-domains/','/premium-domain-deals/','/sold/','/domain-tools/','/premium-domain-names-complete-guide/','/our-services/','/find-a-domain/','/domain-price-index/']:
        u=B+p
        if u not in ok: print(p,'NOT CRAWLED', P.get(u,{}).get('status')); continue
        print(f"{p:42} {len(inl[u]):4}/{len(inl_body[u]):3} d={depth.get(u)}  {anchors[u].most_common(4)}")
    print('\nGeneric anchors:',generic.most_common(8))
    print('\nExternal hosts:',ext.most_common(25)); print('rel:',extrel.most_common(8))
    # links per page
    lc=sorted(((len(r['links']),path(u)) for u,r in ok.items()),reverse=True)
    print('\nMost links on a page:',lc[:5],' median',lc[len(lc)//2])
    # canon / robots issues
    print('\nCanonical != url:',[(path(u),r.get('canon','').replace(B,'')) for u,r in ok.items() if r.get('canon') and r['canon'].split('?')[0]!=u][:15])
    print('noindex in sitemap:',[path(u) for u in sm if u in ok and 'noindex' in (ok[u].get('robots','')+ok[u].get('xrobots','')).lower()][:10])

