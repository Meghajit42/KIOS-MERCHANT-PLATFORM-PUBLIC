'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M03ABrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9228', '--user-data-dir=C:/M03BBrowserProfile'],
  { stdio: 'ignore', windowsHide: true });
let socket;
let sequence = 0;
const pending = new Map();
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function call(method, params = {}, sessionId) {
  const id = ++sequence;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('Browser command timeout')); }, 12000);
    pending.set(id, { resolve, reject, timer });
    socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
}
async function evaluate(session, fn, arg = null) {
  const expression = '(' + fn.toString() + ')(' + JSON.stringify(arg) + ')';
  const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, session);
  if (result.exceptionDetails) throw new Error('Page evaluation rejected');
  return result.result.value;
}
async function test(name, action) {
  try { await action(); output.passed++; output.cases[name] = 'PASS'; }
  catch (error) { output.failed++; output.cases[name] = error.message; }
}
function check(ok) { if (!ok) throw new Error('Browser assertion failed'); }
async function context() {
  const { browserContextId } = await call('Target.createBrowserContext');
  const { targetId } = await call('Target.createTarget', { url: 'http://127.0.0.1:8091/merchant-dev.php', browserContextId });
  const { sessionId } = await call('Target.attachToTarget', { targetId, flatten: true });
  await call('Page.enable', {}, sessionId);
  for (let i = 0; i < 50; i++) {
    if (await evaluate(sessionId, () => document.querySelector('[name=username]') !== null)) return sessionId;
    await delay(100);
  }
  throw new Error('Login page unavailable');
}
async function login(session, credential) {
  await evaluate(session, c => {
    document.querySelector('[name=login_outlet]').value = String(c.outlet);
    document.querySelector('[name=username]').value = c.username;
    document.querySelector('[name=password]').value = c.password;
    document.querySelector('form').submit();
  }, credential);
  for (let i = 0; i < 60; i++) {
    if (await evaluate(session, () => document.body.innerText.includes('Authorized dashboard'))) return;
    await delay(100);
  }
  throw new Error('Authenticated dashboard unavailable');
}
async function action(session, fields) {
  return evaluate(session, async fields => {
    const html = await (await fetch('/merchant-dev.php')).text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    fields._csrf = doc.querySelector('[name=_csrf]')?.value || '';
    const response = await fetch('/merchant-dev.php', { method: 'POST', body: new URLSearchParams(fields) });
    return { status: response.status, body: JSON.parse(await response.text()) };
  }, fields);
}
async function screenshot(session, name, width, height) {
  await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false }, session);
  const { data } = await call('Page.captureScreenshot', { format: 'png' }, session);
  const path = 'C:/Mapped/Results/' + name + '-' + width + 'x' + height + '.png';
  fs.writeFileSync(path, Buffer.from(data, 'base64'));
  output.screenshots.push({ path, width, height, timestamp: new Date().toISOString() });
}

(async()=>{try{
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9228/json/version')).json();break;}catch{}await delay(100);}socket=new WebSocket(v.webSocketDebuggerUrl);
 await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve);socket.addEventListener('error',reject);});socket.addEventListener('message',e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);});
 const a=await context(),b=await context();await login(a,config.A);await login(b,config.B);
 const url=f=>'http://127.0.0.1:8091/merchant-dev.php?layout=1&merchant='+f.receipt.merchant_id+'&outlet='+f.receipt.merchant_outlet_id;
 async function open(s,f){await call('Page.navigate',{url:url(f)},s);for(let i=0;i<60;i++){if(await evaluate(s,()=>!!document.querySelector('#layout-form')))return;await delay(100);}throw new Error('Editor unavailable');}
 await test('owner edits reorders saves and reloads draft',async()=>{await open(a,config.A);await evaluate(a,()=>{const f=document.querySelector('#layout-form');f.elements.namedItem('cards').value='compact';f.querySelectorAll('[data-move=down]')[0].click();f.requestSubmit();});await delay(500);await open(a,config.A);if(!await evaluate(a,()=>document.querySelector('[name=cards]').value==='compact'&&document.querySelector('[data-layout-section]').dataset.layoutSection==='business'))throw new Error('Saved rendering mismatch');});
 await test('merchant B cannot access A editor',async()=>{await open(b,config.B);if(await evaluate(b,async u=>(await fetch(u)).status,url(config.A))!==403)throw new Error('Foreign access accepted');});
 await test('toolbar device switching preserves unsaved state and category IDs are unique',async()=>{await open(a,config.A);if(!await evaluate(a,()=>{document.querySelector('[name=navigation]').value='list';document.querySelector('[name=navigation]').dispatchEvent(new Event('input',{bubbles:true}));for(const d of ['mobile','tablet','desktop'])document.querySelector('[data-device="'+d+'"]').click();const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);return new Set(ids).size===ids.length&&document.querySelector('[name=navigation]').value==='list'&&document.querySelector('#draft-status').textContent.includes('Unsaved')&&[...document.querySelectorAll('.brand-categories a')].every(l=>!!document.querySelector(l.getAttribute('href')));} ))throw new Error('Toolbar or category identity failure');});
 await test('restore defaults retains merchant branding and B remains independent',async()=>{await evaluate(a,()=>document.querySelector('[data-layout-action="layout-defaults"]').click());await delay(500);await open(a,config.A);if(!await evaluate(a,()=>document.querySelector('[name="presentation[primary]"]').value==='#9a3412'&&document.querySelector('[name=cards]').value==='image'&&document.querySelector('[data-layout-section]').dataset.layoutSection==='header'))throw new Error('Inherited defaults mismatch');await open(b,config.B);if(!await evaluate(b,()=>document.querySelector('[name="presentation[primary]"]').value==='#166534'))throw new Error('B inheritance changed');});
 await test('CSRF and foreign logo requests denied',async()=>{const result=await evaluate(b,async f=>{const post=await fetch('/merchant-dev.php',{method:'POST',body:new URLSearchParams({action:'layout-save',_csrf:'bad',merchant:f.receipt.merchant_id,outlet:f.receipt.merchant_outlet_id})});return post.status;},config.A);if(result!==400)throw new Error('CSRF accepted');const logo=await evaluate(a,()=>document.querySelector('.restaurant-logo')?.getAttribute('src'));if(!logo||await evaluate(b,async u=>(await fetch(u)).status,logo)!==403)throw new Error('Foreign logo accepted');});
 await test('three responsive layouts',async()=>{for(const [width,height] of [[1440,900],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);if(await evaluate(a,()=>document.documentElement.scrollWidth>innerWidth))throw new Error('Overflow');await screenshot(a,'m03b-editor',width,height);await evaluate(a,()=>document.querySelector('.layout-preview').scrollIntoView());await screenshot(a,'m03b-preview',width,height);await evaluate(a,()=>scrollTo(0,0));}});
 await test('logout and fresh authentication retain saved draft',async()=>{await evaluate(a,()=>document.querySelector('.studio-header form').requestSubmit());await delay(300);await login(a,config.A);await open(a,config.A);if(!await evaluate(a,()=>document.querySelector('[name="presentation[primary]"]').value==='#9a3412'))throw new Error('Restarted session lost draft');});
 }catch(e){output.failed++;output.cases.runner=e.message;}finally{fs.writeFileSync('C:/Mapped/Results/m03b-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close');}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0);}})();


