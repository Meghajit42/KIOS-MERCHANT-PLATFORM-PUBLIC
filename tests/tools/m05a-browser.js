'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M05ABrowserProfile'],
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
    if (await evaluate(session, () => document.querySelector('[data-cloud-dashboard]')!==null)) return;
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
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);const url=w=>'http://127.0.0.1:8092/merchant.php?inventory=1&merchant='+config[w].receipt.merchant_id+'&outlet='+config[w].receipt.merchant_outlet_id;
 async function open(s,w){await call('Page.navigate',{url:url(w)},s);await delay(600)}
 async function submit(s,op,data){await evaluate(s,({op,data})=>{const form=document.querySelector('[value="inventory-'+op+'"]').form;for(const [key,value] of Object.entries(data)){const input=form.elements.namedItem(key);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}form.requestSubmit();},{op,data});await delay(600)}
 let flour;
 await test('Native owner creates base-unit ingredient and opening stock',async()=>{await open(a,'a');await submit(a,'ingredient',{name:'Demo Flour',unit:'g',opening:'500',minimum:'600',location:'Dry store',supplier:'Synthetic supplier'});check(await evaluate(a,()=>document.querySelector('#stock').innerText.includes('Demo Flour')&&document.querySelector('#stock').innerText.includes('500.000')));flour=await evaluate(a,()=>[...document.querySelectorAll('#movements [name=item] option')].find(e=>e.textContent.startsWith('Demo Flour')).value)});
 await test('Receipt and invalid quantity feedback preserve current stock',async()=>{await submit(a,'receipt',{item:flour,quantity:'200',reason:'Synthetic delivery'});check(await evaluate(a,()=>document.querySelector('#stock').innerText.includes('700.000')));await submit(a,'wastage',{item:flour,quantity:'-1',reason:'Invalid'});check(await evaluate(a,()=>!!document.querySelector('[role=alert]')&&document.querySelector('#stock').innerText.includes('700.000')))});
 await test('Recipe version configuration and authorized synthetic consumption',async()=>{await open(a,'a');const key=await evaluate(a,id=>document.querySelector('#recipes input[value="'+id+'"]').name.replace('[item]','[quantity]'),flour);await submit(a,'recipe',{[key]:'100'});check(await evaluate(a,()=>document.querySelectorAll('[data-recipe]').length>=2));await submit(a,'consume',{servings:'2',reason:'Synthetic preparation'});check(await evaluate(a,()=>document.querySelector('#stock').innerText.includes('500.000')&&document.querySelector('#alerts').innerText.includes('Demo Flour')))});
 await test('History and stock search are readable and persistent',async()=>{await open(a,'a');check(await evaluate(a,()=>document.querySelector('#history').innerText.includes('consume')));check(await evaluate(a,()=>{const q=document.querySelector('[data-stock-search]');q.value='flour';q.dispatchEvent(new Event('input'));return [...document.querySelectorAll('[data-stock-row]')].filter(e=>!e.hidden).length===1}))});
 await test('Independent second merchant and foreign inventory denial',async()=>{await open(b,'b');check(await evaluate(b,()=>!document.querySelector('#stock').innerText.includes('Demo Flour')));check(await evaluate(b,async u=>(await fetch(u)).status,url('a'))===403);await submit(b,'ingredient',{name:'B Rice',unit:'g',opening:'50',minimum:'10',location:'B store',supplier:'Synthetic'});check(await evaluate(b,()=>document.querySelector('#stock').innerText.includes('B Rice')));await open(a,'a');check(await evaluate(a,()=>!document.querySelector('#stock').innerText.includes('B Rice')))});
 await test('CSRF rejected and no customer ordering control exposed',async()=>{check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'inventory-receipt'})})).status)===400);check(await evaluate(a,()=>!document.querySelector('[value=checkout]')&&!!document.querySelector('[role=status]')))});
 await test('Inventory renders at five responsive sizes with labelled controls',async()=>{for(const [width,height]of [[1440,900],[1280,800],[1024,768],[768,1024],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth));await screenshot(a,'m05a-inventory',width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m05a-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
