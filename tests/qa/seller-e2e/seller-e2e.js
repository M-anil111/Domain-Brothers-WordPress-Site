// Seller marketplace functional test over HTTP against staging.
//
// Setup (staging only; never production):
//   1. Create two signed-in test sessions for qa-*@example.com addresses and export their
//      64-hex cookie values as SELLER_A_TOKEN and SELLER_B_TOKEN (never commit them).
//   2. python3 make-fixtures.py <timestamp> <fixtures dir>   (writes the spreadsheets)
//   3. FIXTURES=<fixtures dir> TS=<timestamp> node seller-e2e.js <phase>
// Phases: 1 setup and adding names, 1b extra names, 2 prices and cross-account attempts,
// 3 spreadsheet uploads (bad files, limits, import, replay, sync), 4 submit,
// claim <name>, brk (suspended seller, replayed add), off / on (feature switch).
// Steps that need DNS proof or a simulated clock are run server-side; see MARKETPLACE_PLAN.md.
const { request } = require('playwright'); const fs=require('fs');
const D=process.env.FIXTURES||__dirname, TS=process.env.TS||'';
const sess={a:process.env.SELLER_A_TOKEN||'',b:process.env.SELLER_B_TOKEN||''};
if(!/^[0-9a-f]{64}$/.test(sess.a)||!/^[0-9a-f]{64}$/.test(sess.b)||!TS){ console.error('Set SELLER_A_TOKEN, SELLER_B_TOKEN (64 hex) and TS.'); process.exit(2); }
const B=process.env.BASE_URL||'https://beta.domainbrothers.com';
if(/www\.domainbrothers\.com/.test(B)){ console.error('Refusing to run against production.'); process.exit(2); }
const strip=s=>s.replace(/<[^>]+>/g,'').replace(/&#0?39;|&#8217;/g,"'").replace(/&amp;/g,'&').replace(/&quot;/g,'"').replace(/\s+/g,' ').trim();
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function retry(fn){ for(let i=0;i<6;i++){ await sleep(1500); const r=await fn(); if(r.status()!==508&&r.status()!==503) return r; await sleep(8000*(i+1)); } return fn(); }
async function client(who){ const r=await request.newContext({baseURL:B,extraHTTPHeaders:{cookie:'db_wl='+sess[who]+'; db_in=1'}}); return r; }
async function page(c,q=''){ const r=await retry(()=>c.get('/seller/'+q+(q?'&':'?')+'x='+Date.now())); const h=await r.text(); return {status:r.status(),h,t:(h.match(/name="t" value="([0-9a-f]+)"/)||[])[1]}; }
const msgs=h=>[...h.matchAll(/class="dbsl-msg dbsl-msg--(\w+)"[^>]*>([\s\S]*?)<\/p>/g)].map(m=>m[1]+': '+strip(m[2]));
async function post(c,form,opts={}){ let p=await page(c,opts.q||''); if(!p.t) p=await page(c,''); const f={t:opts.badT?'00':p.t,...form}; const headers={origin:opts.origin||B,referer:(opts.origin||B)+'/seller/'};
 const r=await retry(()=>opts.multipart? c.post('/seller/'+(opts.q||''),{multipart:f,headers,maxRedirects:0}) : c.post('/seller/'+(opts.q||''),{form:f,headers,maxRedirects:0}));
 let h=await r.text(); const loc=r.headers()['location']; if(loc){ h=await (await retry(()=>c.get(loc))).text(); } const m=msgs(h); if(!m.length) m.push('(no message; status '+r.status()+' loc '+(loc||'-')+' body '+strip(h).slice(0,120)+')'); return {status:r.status(),loc,m,h}; }
const file=(n,mime)=>({name:n,mimeType:mime||'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:fs.readFileSync(D+'/'+n)});
const log=(k,v)=>console.log('## '+k+(v&&v.st?' ['+v.st+']':'')+'\n   '+(Array.isArray(v)?v.join('\n   '):JSON.stringify(v)));
(async()=>{ const phase=process.argv[2]||'1'; const a=await client('a'), b=await client('b');
 if(phase==='1'){
  log('A first visit',(await page(a)).status);
  log('setup: no name, bad country, no agree',(await post(a,{db_seller_action:'setup',legal_name:'x',country:'GB',phone:'1'})).m);
  log('setup: missing CSRF',(await post(a,{db_seller_action:'setup',legal_name:'QA E2E',country:'CA',phone:'+1 555 010 0123',agree:'1'},{badT:1})).m);
  log('setup: cross-origin',(await post(a,{db_seller_action:'setup',legal_name:'QA E2E',country:'CA',phone:'+1 555 010 0123',agree:'1'},{origin:'https://evil.example'})).m);
  log('add before setup',(await post(a,{db_seller_action:'add',names:'qa-e2e-early.ca'})).m);
  log('setup: valid (XSS in name)',(await post(a,{db_seller_action:'setup',legal_name:'QA E2E "Quote" & Co',company:'Acme & Sons',country:'ca',phone:'+1 555 010 0123 <b>',agree:'1'})).m);
  log('setup B (same phone as A)',(await post(b,{db_seller_action:'setup',legal_name:'QA E2E B',country:'US',phone:'+1 555 010 0123',agree:'1'})).m);
  const names=[`https://www.QA-E2E-A1-${TS}.ca/path`,`qa-e2e-a2-${TS}.ca`,`QA-E2E-A2-${TS}.CA`,'not a domain',`qa-e2e-a3-${TS}.ca`,`qa-e2e-a4-${TS}.ca`,`qa-e2e-a5-${TS}.ca`,`qa'e2e-${TS}.ca`,`qa_e2e-${TS}.ca`,'google.ca','fuckyou.ca','domainbrothers.com','a.b.c.d.qa.ca','x.zz'].join('\n');
  const r=await post(a,{db_seller_action:'add',names},{q:'?step=add'}); log('add 5 names plus junk',r.m);
 }
 if(phase==='1b'){ const t=(await page(a)).h.match(/<title>([^<]*)/)[1]; log('title',t);
  const names=process.argv[3]?process.argv[3].split(','):['fuckyou.ca','x.zz','shop.example.com','www.brand.co.uk','bakery.ca','torontohomes.com','domainbrothers.com','google.ca']; log('add junk set 2',(await post(a,{db_seller_action:'add',names:names.join('\n')},{q:'?step=add'})).m); }
 if(phase==='2'){
  const p=await page(a,'?step=prices'); const ids=[...p.h.matchAll(/name="row\[(\d+)\]\[price\]"/g)].map(m=>m[1]); log('price rows',ids);
  const f={db_seller_action:'price'}; const set=(i,o,pr,mn)=>{f[`row[${i}][option]`]=o;f[`row[${i}][price]`]=pr;f[`row[${i}][min_offer]`]=mn;};
  set(ids[0],'buy_now','50','0'); set(ids[1],'buy_now','abc','0'); set(ids[2],'buy_now','1000','5000'); set(ids[3],'make_offer','',''); set(ids[4],'buy_now','2500','800');
  set('999999','buy_now','2500','0'); // not mine
  log('prices: invalid mix',(await post(a,f,{q:'?step=prices'})).m);
  const g={db_seller_action:'price'}; ids.forEach((i,k)=>{g[`row[${i}][option]`]='buy_now';g[`row[${i}][price]`]=String(2000+k*500);g[`row[${i}][min_offer]`]='700';}); log('prices: valid',(await post(a,g,{q:'?step=prices'})).m);
  // B tries to change A's rows and remove one
  const bf={db_seller_action:'price'}; bf[`row[${ids[0]}][option]`]='buy_now'; bf[`row[${ids[0]}][price]`]='99999'; bf[`row[${ids[0]}][min_offer]`]='0';
  log('B edits A price',(await post(b,bf)).m); log('B removes A name',(await post(b,{db_seller_action:'remove',id:ids[0]})).m);
  log('B details on A name',(await post(b,{db_seller_action:'details',id:ids[0],description:'pwned'})).m);
  log('B bulk remove A names',(await post(b,{db_seller_action:'bulk',bulk:'remove','ids[]':ids[1]})).m);
  log('A details XSS',(await post(a,{db_seller_action:'details',id:ids[0],description:'Great name "for" <b>bold</b> & brands',keywords:'brand, <i>tech</i>'},{q:'?view=names'})).m);
  const n=await page(a,'?view=names'); log('XSS escaped in names view',[!/<b>bold<\/b>/.test(n.h), !/<i>tech<\/i>/.test(n.h)]);
  log('submit before verify',(await post(a,{db_seller_action:'submit'},{q:'?step=submit'})).m);
  fs.writeFileSync(D+'/ids.json',JSON.stringify(ids));
 }
 if(phase==='3'){
  log('upload renamed exe',(await post(a,{db_seller_action:'import_preview',mode:'import',sheet:file('fake.xlsx')},{multipart:1,q:'?step=add'})).m);
  log('upload 6.7 MB csv',(await post(a,{db_seller_action:'import_preview',mode:'import',sheet:file('huge.csv','text/csv')},{multipart:1,q:'?step=add'})).m);
  const bg=await post(a,{db_seller_action:'import_preview',mode:'import',sheet:file('big.xlsx')},{multipart:1,q:'?step=add'}); log('upload 1005 rows',bg.m); log('big preview summary',strip((bg.h.match(/<div class="dbsl-imp[\s\S]{0,1500}/)||[''])[0]).slice(0,400));
  log('cancel',(await post(a,{db_seller_action:'import_cancel'},{q:'?step=add'})).m);
  const pv=await post(a,{db_seller_action:'import_preview',mode:'import',sheet:file('bulk25.xlsx')},{multipart:1,q:'?step=add'}); log('upload 25 rows',pv.m); log('preview summary',strip((pv.h.match(/<div class="dbsl-imp[\s\S]{0,2500}/)||[''])[0]).slice(0,900));
  log('apply',(await post(a,{db_seller_action:'import_apply'},{q:'?step=add'})).m);
  log('apply replay',(await post(a,{db_seller_action:'import_apply'},{q:'?step=add'})).m);
  const sy=await post(a,{db_seller_action:'import_preview',mode:'sync',sheet:file('sync.xlsx')},{multipart:1,q:'?step=add'}); log('sync preview',sy.m); log('sync summary',strip((sy.h.match(/<div class="dbsl-imp[\s\S]{0,1500}/)||[''])[0]).slice(0,600));
  log('sync apply',(await post(a,{db_seller_action:'import_apply'},{q:'?step=add'})).m);
 }
 if(phase==='4'){ // after server-side verify + trust
  log('submit',(await post(a,{db_seller_action:'submit'},{q:'?step=submit'})).m);
  const n=await page(a,'?view=names'); log('names view status',n.status);
 }
 if(phase==='brk'){ log('suspended B adds',(await post(b,{db_seller_action:'add',names:'bakery.ca'})).m); log('suspended B prices',(await post(b,{db_seller_action:'price','row[1][price]':'5000'})).m); log('suspended B page',strip((await page(b)).h.match(/<h2[^>]*>[^<]*/g).slice(0,2).join(' | ')));
  log('A re-adds a name it already has',(await post(a,{db_seller_action:'add',names:'qa-e2e-x2-'+TS+'.ca'})).m); }
 if(phase==='off'){ for(const u of ['/seller/','/sell-your-domains/']){ const r=await a.get(u+'?x='+Date.now(),{maxRedirects:0}); log('OFF '+u,r.status()); } const h=await (await a.get('/?x='+Date.now())).text(); log('menu link present', /sell-your-domains/.test(h)); }
 if(phase==='on'){ for(const u of ['/seller/','/sell-your-domains/']){ const r=await a.get(u+'?x='+Date.now(),{maxRedirects:0}); log('ON '+u,r.status()); } }
 if(phase==='claim'){ const ids=JSON.parse(fs.readFileSync(D+'/ids.json'));
  const live=process.argv[3]; log('B claims live name '+live,(await post(b,{db_seller_action:'add',names:live},{q:'?step=add'})).m); }
 await a.dispose(); await b.dispose(); })();
