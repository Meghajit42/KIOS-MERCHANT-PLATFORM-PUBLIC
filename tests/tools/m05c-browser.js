'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M05CBrowserProfile'],
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
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);const url=(w,k='pos')=>'http://127.0.0.1:8092/merchant.php?'+k+'=1&merchant='+config[w].receipt.merchant_id+'&outlet='+config[w].receipt.merchant_outlet_id;
 const open=async(s,w,k='pos')=>{await call('Page.navigate',{url:url(w,k)},s);await delay(500)};let service,table,product;
 await test('Occupied table floor category search and real product quantity controls',async()=>{await open(a,'a');check(await evaluate(a,()=>document.querySelector('h1').textContent==='Staff POS'));table=await evaluate(a,()=>{const t=[...document.querySelectorAll('[data-pos-table]')].find(t=>!t.disabled&&t.innerText.includes('POS T4'));t.click();return t.dataset.posTable});check(!!table);product=await evaluate(a,()=>{const p=[...document.querySelectorAll('[data-pos-product]')].find(p=>!p.querySelector('[data-pos-add]').disabled);const search=document.querySelector('[data-pos-search]');search.value=p.querySelector('h3').textContent;search.dispatchEvent(new Event('input'));p.querySelector('[data-pos-quantity]').value='3';p.querySelector('[data-pos-add]').click();document.querySelector('[data-pos-cart] button[aria-label^="Increase quantity"]').click();document.querySelector('[data-pos-cart] input').value='Browser no chilli';document.querySelector('[data-pos-cart] input').dispatchEvent(new Event('input'));return p.dataset.posProduct});check(await evaluate(a,()=>document.querySelector('[data-pos-cart]').textContent.includes('Quantity 4')));});
 await test('Save draft reload and authoritative snapshot review',async()=>{await evaluate(a,()=>document.querySelector('[value="pos-save"]').form.querySelector('button').click());await delay(500);service=await evaluate(a,()=>new URL(location.href).searchParams.get('service'));check(!!service);check(await evaluate(a,()=>document.querySelector('.pos-saved').textContent.includes('× 4')));await call('Page.reload',{},a);await delay(500);check(await evaluate(a,()=>document.querySelector('[data-pos-cart]').textContent.includes('Quantity 4')&&document.querySelector('[data-pos-cart] input').value==='Browser no chilli'));});
 await test('Kitchen submission persists one service and clear nonfinancial status',async()=>{await evaluate(a,()=>document.querySelector('[data-pos-submit]').click());await delay(500);check(await evaluate(a,id=>document.querySelector('[data-service="'+id+'"]').textContent.includes('PREPARING'),service));check(await evaluate(a,()=>document.body.innerText.includes('No payment is due')));await screenshot(a,'m05c-submitted',1280,800);});
 await test('Actual authorized kitchen KOT preparing ready and POS synchronization',async()=>{await open(a,'a','service-kitchen');check(await evaluate(a,()=>document.body.innerText.includes('Browser no chilli')));await evaluate(a,()=>document.querySelector('[data-service-task] [value="pos-task"]').form.querySelector('button').click());await delay(500);check(await evaluate(a,()=>document.querySelector('[data-service-task]').innerText.includes('PREPARING')));await evaluate(a,()=>document.querySelector('[data-service-task] [value="pos-task"]').form.querySelector('button').click());await delay(500);check(await evaluate(a,()=>document.querySelector('[data-service-task]').innerText.includes('READY')));await screenshot(a,'m05c-kitchen',1280,800);await open(a,'a');check(await evaluate(a,id=>document.querySelector('[data-service="'+id+'"]').innerText.includes('READY'),service));await evaluate(a,id=>document.querySelector('[data-service="'+id+'"] [value="pos-served"]').form.querySelector('button').click(),service);await delay(500);check(await evaluate(a,id=>document.querySelector('[data-service="'+id+'"]').innerText.includes('SERVED'),service));await open(a,'a');check(await evaluate(a,id=>[...document.querySelectorAll('[data-pos-table]')].find(t=>t.dataset.posTable===id).innerText.includes('OCCUPIED'),table));});
 await test('Cross merchant service access and invalid CSRF denied',async()=>{await open(b,'b');check(await evaluate(b,async u=>(await fetch(u)).status,url('a'))===403);check(await evaluate(b,()=>!document.body.innerText.includes('Browser no chilli')));check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'pos-submit'})})).status)===400);});
 await test('Legitimate cashier and kitchen sign-in land on their authorized workspaces',async()=>{const staff=JSON.parse(fs.readFileSync('C:/Mapped/Results/m05b-staff.json','utf8'));for(const role of ['cashier','kitchen']){const session=await context();await login(session,staff[role]);check(await evaluate(session,()=>document.querySelector('h1').textContent)===(role==='cashier'?'Staff POS':'Internal kitchen'));check(await evaluate(session,async u=>(await fetch(u)).status,url('a',role==='cashier'?'service-kitchen':'pos'))===403);}});
 await test('Desktop tablet portrait mobile POS readable without overflow',async()=>{await open(a,'a');for(const [width,height]of [[1440,900],[1280,800],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth));await evaluate(a,()=>document.querySelector('.pos-layout').scrollIntoView({block:'start'}));await screenshot(a,'m05c-pos',width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m05c-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
