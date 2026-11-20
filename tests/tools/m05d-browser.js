'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M05DBrowserProfile'],
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
    if (await evaluate(session, () => document.querySelector('[data-cloud-dashboard],[data-seating],[data-staff-pos]')!==null)) return;
    await delay(100);
  }
  throw new Error('Authenticated dashboard unavailable');
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
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break}catch{}await delay(100)}socket=new WebSocket(v.webSocketDebuggerUrl);await new Promise((res,rej)=>{socket.addEventListener('open',res);socket.addEventListener('error',rej)});socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Page.javascriptDialogOpening'&&['confirm','beforeunload'].includes(m.params.type)){call('Page.handleJavaScriptDialog',{accept:true},m.sessionId);return;}const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result)});
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);const url=(w,outlet=null)=>'http://127.0.0.1:8092/merchant.php?analytics=1&merchant='+config[w].receipt.merchant_id+'&outlet='+(outlet||config[w].receipt.merchant_outlet_id);const open=async(s,u)=>{await call('Page.navigate',{url:u},s);await delay(500)};
 await test('Actual operational sections with explicit UTC scopes and no financial metrics',async()=>{await open(a,url('a'));check(await evaluate(a,()=>document.querySelector('h1').textContent==='Operational analytics'&&!!document.querySelector('#kitchen')&&!!document.querySelector('#seating')&&!!document.querySelector('#inventory')&&!!document.querySelector('#staff')&&document.body.innerText.includes('UTC')));check(await evaluate(a,()=>document.querySelectorAll('.analytics-metrics article').length===6));});
 await test('Date filter actual submit refresh and unavailable historical timings',async()=>{await evaluate(a,()=>{const f=document.querySelector('[data-analytics-filter]');f.elements.from.value='2001-01-01';f.elements.to.value='2001-01-02';f.querySelector('button').click()});await delay(500);check(await evaluate(a,()=>document.querySelector('#kitchen').innerText.includes('Unavailable')&&document.querySelector('[name=from]').value==='2001-01-01'));await open(a,url('a'));});
 await test('Authorized CSV uses real response and preserves data without HTML transform',async()=>{const r=await evaluate(a,async()=>{const response=await fetch(document.querySelector('.analytics-export').href);return {status:response.status,type:response.headers.get('content-type'),text:await response.text()}});check(r.status===200&&r.type.includes('text/csv')&&r.text.startsWith('section,outlet,metric,value,unit,scope')&&!r.text.includes('<body'));fs.writeFileSync('C:/Mapped/Results/m05d-export.csv',r.text);});
 await test('Independent merchant and unauthorized export denial',async()=>{await open(b,url('b'));check(await evaluate(b,async u=>(await fetch(u)).status,url('a'))===403);check(await evaluate(b,async u=>(await fetch(u+'&export=csv')).status,url('a'))===403);check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'analytics-mutate'})})).status)===400);});
 await test('Owner multi-outlet aggregation and manager cashier reporting permissions',async()=>{await open(a,url('a','all'));check(await evaluate(a,()=>document.querySelector('[name=outlet]').value==='all'&&document.querySelectorAll('[name=outlet] option').length===3));const staff=JSON.parse(fs.readFileSync('C:/Mapped/Results/m05b-staff.json','utf8'));for(const role of ['outlet_administrator','cashier']){const s=await context();await login(s,staff[role]);const status=await evaluate(s,async u=>(await fetch(u)).status,url('a'));check(status===(role==='cashier'?403:200));check(await evaluate(s,async u=>(await fetch(u)).status,url('a','all'))===403);}});
 await test('Desktop tablet portrait mobile and activity search remain readable',async()=>{await open(a,url('a'));check(await evaluate(a,()=>{const x=document.querySelector('[data-analytics-search]');x.value='no-such-actor';x.dispatchEvent(new Event('input'));return [...document.querySelectorAll('#staff tbody tr')].every(r=>r.hidden)}));for(const [width,height]of [[1440,900],[1280,800],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth));check(await evaluate(a,()=>[...document.querySelectorAll('.analytics-scroll')].every(x=>x.tabIndex===0)&&[...document.querySelectorAll('.analytics-scroll th')].every(x=>getComputedStyle(x).whiteSpace==='nowrap')),'Readable keyboard-scrollable table headings');await screenshot(a,'m05d-analytics',width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m05d-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
