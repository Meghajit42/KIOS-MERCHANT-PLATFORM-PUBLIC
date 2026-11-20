'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M06BBrowserProfile'],
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
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break}catch{}await delay(100)}socket=new WebSocket(v.webSocketDebuggerUrl);await new Promise((res,rej)=>{socket.addEventListener('open',res);socket.addEventListener('error',rej)});socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Page.javascriptDialogOpening'){call('Page.handleJavaScriptDialog',{accept:true},m.sessionId);return;}const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result)});
 const a=await context(),b=await context(),c=await context(),admin=await context();await login(a,config.a);await login(b,config.b);await login(c,{outlet:meta.merchants.c.loginOutlet,username:secrets.owner_c_username,password:secrets.owner_c_password});await login(admin,{outlet:meta.platform.loginOutlet,username:secrets.platform_username,password:secrets.platform_password});
 const url=w=>'http://127.0.0.1:8092/merchant.php?subscription=1&merchant='+meta.merchants[w].merchant+'&outlet='+meta.merchants[w].outlet;
 async function open(s,u){await call('Page.navigate',{url:u},s);for(let i=0;i<100;i++){if(await evaluate(s,u=>location.href===u&&document.readyState==='complete',u))return;await delay(100)}throw Error('Page readiness timeout')}
 async function transition(w,action,fields={}){await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');await evaluate(admin,({id,action,fields})=>{const box=document.querySelector('[data-lifecycle-merchant="'+id+'"]');box.querySelector('details').open=true;const f=box.querySelector('form');f.elements.transition.value=action;f.elements.transition.dispatchEvent(new Event('change',{bubbles:true}));f.elements.reason.value='Synthetic browser lifecycle '+action;for(const[k,v]of Object.entries(fields))f.elements[k].value=v;f.querySelector('button').click()},{id:meta.merchants[w].merchant,action,fields});for(let i=0;i<100;i++){if(await evaluate(admin,()=>location.search.includes('saved=1')&&document.readyState==='complete'))return;await delay(100)}throw Error('Lifecycle save did not complete')}
 await test('Three native merchant subscriptions expose active lifecycle UTC periods without financial obligation',async()=>{for(const[s,w]of[[a,'a'],[b,'b'],[c,'c']]){await open(s,url(w));check(await evaluate(s,()=>!!document.querySelector('[data-lifecycle-state=ACTIVE]')&&document.body.innerText.includes('Upcoming renewal review')&&document.body.innerText.includes('No automatic renewal')))}});
 await test('Native owner cannot administer lifecycle and CSRF invalid requests are rejected',async()=>{check(await evaluate(c,async()=>{const html=await(await fetch('/merchant.php')).text();const csrf=new DOMParser().parseFromString(html,'text/html').querySelector('[name=_csrf]')?.value;return(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:csrf,action:'plans-lifecycle',request:'a'.repeat(32),merchant:document.querySelector('main[data-merchant]').dataset.merchant,expected:'0',assignment_expected:'0',transition:'suspend',choice:'starter:1',effective:'2026-11-19T00:00',expires:'2027-11-19T00:00',trial_end:'',grace_days:'0',renewable:'1',reason:'Unauthorized native owner'})})).status})===403);check(await evaluate(c,async()=>(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'plans-lifecycle'})})).status)===400)});
 await test('Platform suspension refreshes merchant state and reactivation persists immutable history',async()=>{await transition('c','suspend');await open(c,url('c'));check(await evaluate(c,()=>!!document.querySelector('[data-lifecycle-state=SUSPENDED]')));check(await evaluate(c,async u=>(await fetch(u.replace('subscription=1','restaurant=1&section=menu'))).status,url('c'))===403);await transition('c','reactivate',{choice:'starter:1'});await open(c,url('c'));check(await evaluate(c,()=>!!document.querySelector('[data-lifecycle-state=ACTIVE]')&&document.querySelector('#lifecycle-history').innerText.includes('reactivate')))});
 await test('Scheduled change and explicit discard use real native forms and survive reload',async()=>{const future=new Date(Date.now()+3600000).toISOString().slice(0,16);await transition('c','schedule',{choice:'growth:1',effective:future});await open(c,url('c'));check(await evaluate(c,()=>document.body.innerText.includes('Scheduled change:')&&document.body.innerText.includes('Growth')));await transition('c','discard-schedule');await open(c,url('c'));check(await evaluate(c,()=>!document.body.innerText.includes('Scheduled change:')&&document.querySelector('#lifecycle-history').innerText.includes('discard-schedule')))});
 await test('Cancellation at period end remains active until boundary and platform can rescind through reactivation',async()=>{await transition('c','cancel-end');await open(c,url('c'));check(await evaluate(c,()=>!!document.querySelector('[data-lifecycle-state=ACTIVE]')&&!document.querySelector('.lifecycle-dates').innerText.includes('Cancellation\nNot scheduled')));await transition('c','reactivate',{choice:'starter:1'});await open(c,url('c'));check(await evaluate(c,()=>document.querySelector('.lifecycle-dates').innerText.includes('Cancellation\nNot scheduled')))});
 await test('Independent merchant subscription and original RBAC remain isolated',async()=>{check(await evaluate(c,async u=>(await fetch(u)).status,url('a'))===403);check(await evaluate(b,async u=>(await fetch(u.replace('subscription=1','analytics=1'))).status,url('b'))===403);check(await evaluate(a,async u=>(await fetch(u.replace('subscription=1','pos=1'))).status,url('a'))===200)});
 await test('Desktop landscape portrait and mobile lifecycle layout has no page overflow',async()=>{await open(a,url('a'));await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');for(const[width,height]of[[1440,900],[1280,800],[800,1280],[390,844]])for(const[s,name]of[[a,'merchant'],[admin,'platform']]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},s);check(await evaluate(s,()=>document.documentElement.scrollWidth<=innerWidth&&!/[\u00c2\u00c3]/.test(document.body.innerText)));await screenshot(s,'m06b-'+name,width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m06b-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
