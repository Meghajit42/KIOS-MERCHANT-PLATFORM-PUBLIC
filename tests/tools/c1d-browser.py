import os,json,subprocess,hashlib,mimetypes,sys
from pathlib import Path
from datetime import datetime,timezone
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'docs/fixtures/c1d';OUT.mkdir(parents=True,exist_ok=True)
screens=['customer-sweets','customer-snacks','customer-dahi','customer-cart-piece','customer-cart-kg','customer-cart-mixed','admin-catalogue','admin-needs-review','admin-kaju-barfi','admin-boil-cake','admin-malai-gari','admin-khirtua','admin-kala-kalo-jam','admin-product-edit','admin-audit']
stale_only='--stale-only' in sys.argv
if stale_only:screens=['admin-stale-review']
commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
assets={p.relative_to(ROOT).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'public/assets').rglob('*') if p.is_file()}
manifest=json.loads((ROOT/'docs/GOPAL_C1D_FIXTURE_MANIFEST.json').read_text())['fixtures'] if stale_only else []
for screen in screens:
 result=subprocess.run([r'C:/xampp/php/php.exe',str(ROOT/'tests/tools/export-c1-fixtures.php'),screen],cwd=ROOT,capture_output=True,env=os.environ)
 if result.returncode or result.stderr:raise RuntimeError(screen+': '+result.stderr.decode())
 html=result.stdout.decode('utf-8')
 if screen.startswith('customer-'):
  assert not any(x in html for x in ['needs_owner_confirmation','source_conflict','possible_duplicate','Shop confirmation required'])
 (OUT/(screen+'.html')).write_text(html,encoding='utf-8')
 manifest.append(dict(fixture=screen+'.html',route='public/index.php' if screen.startswith('customer') else ('public/admin/products.php' if screen=='admin-catalogue' else 'public/admin/product.php' if screen=='admin-product-edit' else 'public/admin/changes.php' if screen=='admin-audit' else 'public/admin/review.php'),database='kios_c1b_demo_20261102181004',generated=datetime.now(timezone.utc).isoformat(),application_commit=commit,source_count=77,sanitization=['CSRF values','checkout intent values','in-memory session only'],sha256=hashlib.sha256(result.stdout).hexdigest()))
(ROOT/'docs/GOPAL_C1D_FIXTURE_MANIFEST.json').write_text(json.dumps(dict(fixtures=manifest,asset_hashes=assets,connection_adapter='CLI-only test factory, read-only transaction; real production route services and checks',transport='Playwright static request fulfillment; no listening server'),indent=2))
metrics=json.loads((ROOT/'docs/GOPAL_C1D_RESPONSIVE_METRICS.json').read_text()) if stale_only else []
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=r'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless=True)
 context=browser.new_context()
 def serve(route):
  from urllib.parse import urlparse,unquote
  req=route.request;url=urlparse(req.url)
  if req.method!='GET' or url.hostname!='c1-fixture.local':route.abort();return
  rel=unquote(url.path).lstrip('/')
  path=(OUT/rel) if rel.endswith('.html') else (ROOT/'public'/rel)
  allowed=OUT if rel.endswith('.html') else ROOT/'public'
  if not path.resolve().is_relative_to(allowed.resolve()) or not path.is_file():route.fulfill(status=404,body='Not found');return
  route.fulfill(status=200,body=path.read_bytes(),content_type=mimetypes.guess_type(path.name)[0] or 'application/octet-stream')
 context.route('**/*',serve)
 page=context.new_page()
 for width,height in [(390,844),(768,1024),(1024,768),(1440,900)]:
  page.set_viewport_size(dict(width=width,height=height))
  for screen in screens:
   page.goto('http://c1-fixture.local/'+screen+'.html',wait_until='networkidle')
   page.evaluate("document.querySelectorAll('img').forEach(x=>x.loading='eager')")
   page.wait_for_function("Array.from(document.images).every(x=>x.complete)")
   m=page.evaluate("""() => {
    const visible=e=>e.getClientRects().length>0;
    const controls=[...document.querySelectorAll('button,select,input:not([type=hidden]):not([type=radio]):not([type=checkbox]),.category-chip,.admin-button')].filter(visible);
    const dims=controls.map(e=>{const r=e.getBoundingClientRect();return {label:(e.innerText||e.getAttribute('aria-label')||e.name||e.tagName).trim(),width:r.width,height:r.height}});
    const clipped=[...document.querySelectorAll('h1,h2,h3,.product-price,.cart-choice')].filter(visible).filter(e=>e.scrollWidth>e.clientWidth+2).map(e=>e.textContent.trim());
    const overlaps=[];
    for(let i=0;i<controls.length;i++)for(let j=i+1;j<controls.length;j++){
      const a=controls[i],b=controls[j];if(a.contains(b)||b.contains(a))continue;
      const x=a.getBoundingClientRect(),y=b.getBoundingClientRect();
      if(Math.min(x.right,y.right)-Math.max(x.left,y.left)>2 && Math.min(x.bottom,y.bottom)-Math.max(x.top,y.top)>2)overlaps.push([dims[i].label,dims[j].label]);
    }
    return {document_width:document.documentElement.scrollWidth,client_width:document.documentElement.clientWidth,overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth+1,missing_images:[...document.images].filter(e=>(e.currentSrc||e.getAttribute('src'))&&(!e.complete||e.naturalWidth===0)).length,controls:dims,clipping:clipped,overlaps:overlaps};
   }""")
   m.update(screen=screen,viewport=[width,height]);metrics.append(m);print(screen,width,flush=True)
   if width==390 and screen in ['customer-snacks','customer-dahi','admin-kaju-barfi']:
    page.screenshot(path=str(OUT/(screen+'-390.png')),full_page=True)
 browser.close()
(ROOT/'docs/GOPAL_C1D_RESPONSIVE_METRICS.json').write_text(json.dumps(metrics,indent=2))
print(json.dumps(dict(fixtures=len(screens),evaluations=len(metrics),overflow=sum(m['overflow'] for m in metrics),broken=sum(m['missing_images'] for m in metrics),clipping=sum(len(m['clipping']) for m in metrics),small_mobile=[dict(screen=m['screen'],controls=[c for c in m['controls'] if c['height']<43.9]) for m in metrics if m['viewport'][0]==390 and any(c['height']<43.9 for c in m['controls'])])))
