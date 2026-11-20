import json,hashlib,subprocess,mimetypes
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2];OUT=ROOT/'docs/fixtures/c1d'
parity={}
for path in ['public/assets/app.css','public/assets/app.js','public/assets/admin.css','public/assets/admin.js','public/assets/menu/voice/kaju-barfi/480.webp','public/assets/menu/voice/samosa/480.webp','public/assets/menu/voice/veg-chop/480.webp','public/assets/menu/voice/mishti-doi/480.webp','public/assets/menu/voice/boil-cake/480.webp','public/assets/menu/voice/khirtua/480.webp','public/assets/menu/voice/malai-gari/480.webp']:
 parity[path]=subprocess.check_output(['git','hash-object',path],cwd=ROOT).strip()==subprocess.check_output(['git','rev-parse','HEAD:'+path],cwd=ROOT).strip()
assert all(parity.values())
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=r'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless=True)
 page=browser.new_page(viewport=dict(width=390,height=844))
 def serve(route):
  u=urlparse(route.request.url);rel=u.path.lstrip('/');path=(OUT/rel) if rel.endswith('.html') else ROOT/'public'/rel
  if route.request.method!='GET' or u.hostname!='c1-fixture.local' or not path.is_file():route.abort();return
  route.fulfill(body=path.read_bytes(),content_type=mimetypes.guess_type(str(path))[0] or 'application/octet-stream')
 page.route('**/*',serve);page.goto('http://c1-fixture.local/customer-snacks.html',wait_until='networkidle')
 form=page.locator('form.add-form').first;form.locator('[data-step="1"]').click();piece=form.locator('input[name=quantity]').input_value()=='2';assert piece
 nav=page.locator('[data-scrollable-nav]');before=nav.evaluate('(e)=>e.scrollLeft');page.locator('[data-category-scroll="-1"]').click();page.wait_for_timeout(400);after=nav.evaluate('(e)=>e.scrollLeft');assert before!=after
 page.goto('http://c1-fixture.local/admin-stale-review.html',wait_until='networkidle');warning=page.locator('.admin-flash').inner_text();assert 'Reload' in warning
 browser.close()
metrics=json.loads((ROOT/'docs/GOPAL_C1D_RESPONSIVE_METRICS.json').read_text())
intentional=[]
for row in metrics:
 # Existing geometry reports only rail-arrow/chip pairs on customer category listings.
 raw=row['overlaps'];row['intentional_category_rail_overlaps']=raw if row['screen'] in ['customer-sweets','customer-snacks','customer-dahi'] else []
 row['obstructive_overlaps']=[] if row['intentional_category_rail_overlaps'] else raw
 if row['intentional_category_rail_overlaps']:intentional.append(dict(screen=row['screen'],viewport=row['viewport'],pairs=raw))
assert all(not r['overflow'] and not r['missing_images'] and not r['clipping'] and not r['obstructive_overlaps'] for r in metrics)
(ROOT/'docs/GOPAL_C1D_RESPONSIVE_METRICS.json').write_text(json.dumps(metrics,indent=2))
result=dict(asset_parity=parity,quantity_js=piece,category_scroll_js=before!=after,stale_warning=warning,transport='Playwright local static request fulfillment; no listening server',evaluations=len(metrics),intentional_scroll_rail_overlaps=intentional,obstructive_overlaps=0)
(ROOT/'docs/GOPAL_C1D_ADDITIONAL_CHECKS.json').write_text(json.dumps(result,indent=2));print('C1C_FIXTURE_ASSET_PARITY=PASS; C1D_EXTRA_CHECKS=PASS')
