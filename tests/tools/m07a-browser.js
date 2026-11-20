'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M07ABrowserProfile'],
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
function check(ok,message='Browser assertion failed') { if (!ok) throw new Error(message); }
async function context() {
  const { browserContextId } = await call('Target.createBrowserContext');
  const { targetId } = await call('Target.createTarget', { url: 'http://127.0.0.1:8092/merchant.php', browserContextId });
  const { sessionId } = await call('Target.attachToTarget', { targetId, flatten: true });
  await call('Page.enable', {}, sessionId);
  await call('Runtime.enable', {}, sessionId);
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
    if (await evaluate(session, () => document.querySelector('[data-cloud-dashboard],[data-seating],[data-staff-pos],a[href="/merchant.php?plans=1"]')!==null)) return;
    await delay(100);
  }
  output.loginFailure=await evaluate(session,()=>({url:location.href,title:document.title,heading:document.querySelector('h1,h2')?.textContent,loginForm:!!document.querySelector('[name=username]'),status:document.querySelector('[role=alert]')?.textContent,body:document.body.innerText.slice(0,300)}));throw new Error('Authenticated dashboard unavailable');
}
async function action(session, fields) {
  return evaluate(session, async fields => {
    const html = await (await fetch('/merchant.php')).text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    fields._csrf = doc.querySelector('[name=_csrf]')?.value || '';
    const response = await fetch('/merchant.php', { method: 'POST', body: new URLSearchParams(fields) });
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
let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break}catch{}await delay(100)}socket=new WebSocket(v.webSocketDebuggerUrl);await new Promise((r,j)=>{socket.addEventListener('open',r);socket.addEventListener('error',j)});socket.addEventListener('message',e=>{const m=JSON.parse(e.data);const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result)});
const owner=await context(),foreign=await context(),anonymous=await context();await login(owner,config.a);await login(foreign,config.b);const a=meta.merchants.a;const route=k=>'/api/v1/'+k+'?merchant='+a.merchant+'&outlet='+a.outlet;
async function get(s,url,method='GET',headers={}){const response=await evaluate(s,async a=>{const r=await fetch(a.url,{method:a.method,headers:a.headers});return{status:r.status,body:await r.text(),headers:Object.fromEntries(r.headers.entries())}},{url,method,headers});let code=null;try{code=JSON.parse(response.body).error?.code??null}catch{}(output.requests??=[]).push({path:url.split('?')[0],method,status:response.status,error:code});return response;}
await test('Native authenticated browser reads all six private API capabilities',async()=>{for(const k of['merchant','outlet','catalogue','availability','configuration','operational-status']){const r=await get(owner,route(k));check(r.status===200,'Expected 200 for '+k+'; got '+r.status+' '+r.body);const b=JSON.parse(r.body);check(b.api_version==='v1'&&b.error===null&&!r.body.includes('password_hash'));}});
await test('Foreign merchant and anonymous browser contexts are denied',async()=>{check((await get(foreign,route('catalogue'))).status===403);check((await get(anonymous,route('merchant'))).status===401);});
await test('HEAD is bodyless methods are blocked and cookie reads have no public CORS grant',async()=>{let r=await get(owner,route('merchant'),'HEAD');check(r.status===200&&r.body==='');r=await get(owner,route('merchant'),'POST');check(r.status===405&&r.headers.allow==='GET, HEAD');r=await get(owner,route('merchant'),'GET',{Authorization:'Bearer synthetic-invalid'});check(r.status===401);check(!r.headers['access-control-allow-origin']);});
await test('Pagination unknown query fields and new version failures are explicit JSON',async()=>{let r=await get(owner,route('catalogue')+'&per_page=1');check(r.status===200&&JSON.parse(r.body).pagination.per_page===1);check((await get(owner,route('merchant')+'&token=synthetic')).status===400);check((await get(owner,route('merchant').replace('/v1/','/v2/'))).status===404);});
await test('Actual browser JSON endpoint renders without credential fields at three resolutions',async()=>{await call('Page.navigate',{url:'http://127.0.0.1:8092'+route('catalogue')},owner);await delay(500);for(const[w,h]of[[1440,900],[800,1280],[390,844]])await screenshot(owner,'m07a-api-browser',w,h);check(await evaluate(owner,()=>{const text=document.body.innerText;const b=JSON.parse(text);return b.api_version==='v1'&&b.error===null&&Array.isArray(b.data)&&!text.includes('password_hash')}));});
}catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m07a-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();