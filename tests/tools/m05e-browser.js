'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M05EBrowserProfile'],
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
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break}catch{}await delay(100)}socket=new WebSocket(v.webSocketDebuggerUrl);await new Promise((res,rej)=>{socket.addEventListener('open',res);socket.addEventListener('error',rej)});socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Page.javascriptDialogOpening'){call('Page.handleJavaScriptDialog',{accept:true},m.sessionId);return;}const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result)});
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);const url=w=>'http://127.0.0.1:8092/merchant.php?billing=1&merchant='+config[w].receipt.merchant_id+'&outlet='+config[w].receipt.merchant_outlet_id;
 async function open(s,u){await call('Page.navigate',{url:u},s);for(let i=0;i<100;i++){if(await evaluate(s,u=>location.href===u&&document.readyState==='complete',u))return;await delay(100)}throw Error('Navigation timeout')}
 async function save(){const v=await evaluate(a,()=>document.querySelector('[name=version]').value);await evaluate(a,()=>document.querySelector('[data-bill-form] button').click());for(let i=0;i<100;i++){if(await evaluate(a,v=>!!document.querySelector('[data-bill-form]')&&document.querySelector('[name=version]').value!==v,v))return;await delay(100)}throw Error('Bill save failed')}
 await test('Native session source review and demonstration labels',async()=>{await open(a,url('a'));const links=await evaluate(a,()=>[...document.querySelectorAll('.billing-service')].map(x=>x.href));check(links.length>=2);for(const u of links){await open(a,u);if(await evaluate(a,()=>!!document.querySelector('[data-bill-form]')))break}check(await evaluate(a,()=>!!document.querySelector('[data-bill-form]')&&document.body.innerText.includes('DEMONSTRATION — NOT A RECEIPT OR TAX INVOICE')));});
 await test('Equal split adjustment save reload history',async()=>{await evaluate(a,()=>{const f=document.querySelector('[data-bill-form]');f.elements.people.value='3';f.elements.mode.value='equal';f.elements.discount.value='1';f.elements.reason.value='Synthetic browser discount';f.dispatchEvent(new Event('input',{bubbles:true}))});await save();await open(a,await evaluate(a,()=>location.href));check(await evaluate(a,()=>document.querySelector('.billing-total ol').children.length===3&&!!document.querySelector('#history details')));});
 await test('Item allocations actual save and persistence',async()=>{await evaluate(a,()=>{const f=document.querySelector('[data-bill-form]');f.elements.people.value='2';f.elements.mode.value='items';f.dispatchEvent(new Event('input',{bubbles:true}));for(const row of f.querySelectorAll('[data-item-split] fieldset')){const xs=row.querySelectorAll('input');xs[0].value=String(Number(xs[0].max)-1);xs[1].value='1'}});await save();check(await evaluate(a,()=>document.querySelector('[name=mode]').value==='items'&&document.querySelector('.billing-total ol').children.length===2));});
 await test('Foreign access CSRF and exact stale revision denial',async()=>{check(await evaluate(b,async u=>(await fetch(u)).status,url('a'))===403);check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'billing-save'})})).status)===400);const old=await evaluate(a,()=>[...new FormData(document.querySelector('[data-bill-form]')).entries()]);await save();const response=await evaluate(a,async pairs=>{const p=new URLSearchParams(pairs);p.set('request','ab'.repeat(16));const r=await fetch('/merchant.php',{method:'POST',body:p});return{status:r.status,text:await r.text()}},old);check(response.status===403&&response.text.includes('Operation denied'));});
 await test('Desktop tablet portrait mobile layouts',async()=>{for(const[width,height]of[[1440,900],[1280,800],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth));await screenshot(a,'m05e-billing',width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m05e-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
