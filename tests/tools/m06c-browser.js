'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M06CBrowserProfile'],
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
 const admin=await context(),owner=await context(),operator=await context();await login(admin,{outlet:meta.platform.loginOutlet,username:secrets.platform_username,password:secrets.platform_password});await login(owner,config.a);let e=null,eOwner=null;
 async function open(s,u){await call('Page.navigate',{url:u},s);for(let i=0;i<120;i++){if(await evaluate(s,u=>location.href===u&&document.readyState==='complete',u))return;await delay(100)}throw Error('Page not ready')}
 async function submit(s,selector){check(await evaluate(s,selector=>{const f=document.querySelector(selector);if(!f||!f.reportValidity())return false;window.__paNavigation=true;f.requestSubmit();return true},selector),'Displayed form invalid');for(let i=0;i<120;i++){if(await evaluate(s,()=>window.__paNavigation===undefined&&document.readyState==='complete')){check(await evaluate(s,()=>!document.querySelector('.pa-error,[role=alert]')),'Server rejected displayed form');return}await delay(100)}throw Error('Form navigation timeout')}
 const detail=m=>'http://127.0.0.1:8092/merchant.php?platform=detail&merchant='+m;
 async function change(m,op,data){await open(admin,detail(m));await evaluate(admin,({op,data})=>{const f=document.querySelector('[name=op][value="'+op+'"]').form;f.closest('details').open=true;for(const[k,v]of Object.entries(data))f.elements['data['+k+']'].value=v;f.elements.reason.value='Synthetic browser '+op;f.elements.confirm.checked=true},{op,data});await submit(admin,'[name=op][value="'+op+'"]');}
 // submit accepts either a form or one of its named controls.
 const originalSubmit=submit;submit=async(s,selector)=>{const isForm=await evaluate(s,selector=>document.querySelector(selector)?.tagName==='FORM',selector);if(!isForm){await evaluate(s,selector=>{document.querySelector(selector).form.id='pa-selected-form'},selector);selector='#pa-selected-form'}return originalSubmit(s,selector)};
 await test('Native owners cannot access any platform page or mutate with their valid CSRF',async()=>{for(const section of['overview','merchants','outlets','subscriptions','audit','create'])check(await evaluate(owner,async section=>(await fetch('/merchant.php?platform='+section)).status,section)===403);check(await evaluate(owner,async()=>{const html=await(await fetch('/merchant.php')).text();const token=new DOMParser().parseFromString(html,'text/html').querySelector('[name=_csrf]').value;return(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:token,action:'platform-admin'})})).status})===403);check(await evaluate(admin,async()=>(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'platform-admin'})})).status)===400);check(await evaluate(admin,async()=>{const html=await(await fetch('/merchant.php')).text();const token=new DOMParser().parseFromString(html,'text/html').querySelector('[name=_csrf]').value;return(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:token,action:'onboarding-create'})})).status})===403)});
 await test('Platform navigation overview directory safe filters and pagination are actual protected pages',async()=>{for(const section of['overview','merchants','outlets','subscriptions','audit','create']){await open(admin,'http://127.0.0.1:8092/merchant.php?platform='+section);check(await evaluate(admin,()=>document.querySelectorAll('.pa-sidebar nav a').length===6&&!!document.querySelector('[data-platform-console]')))}await open(admin,'http://127.0.0.1:8092/merchant.php?platform=merchants&q=Synthetic%20pagination%20fixture');check(await evaluate(admin,()=>document.body.innerText.includes('21 matching merchants')&&document.querySelectorAll('.pa-table tbody tr').length===20));await open(admin,'http://127.0.0.1:8092/merchant.php?platform=merchants&q=Synthetic%20pagination%20fixture&page=2');check(await evaluate(admin,()=>document.querySelectorAll('.pa-table tbody tr').length===1))});
 await test('Controlled browser creates a merchant and privately establishes its native owner credential',async()=>{await open(admin,'http://127.0.0.1:8092/merchant.php?platform=create');await evaluate(admin,()=>{const f=document.querySelector('[value=platform-create]').form;const data={business_name:'Synthetic browser platform merchant',admin_contact:'platform-browser@example.test',outlet_name:'Synthetic browser outlet',owner_name:'Synthetic browser owner',owner_username:'m06c-browser-owner',business_type:'restaurant',opening_time:'08:00',closing_time:'22:00'};for(const[k,v]of Object.entries(data))f.elements['data['+k+']'].value=v;f.elements.confirm.checked=true});await submit(admin,'[value=platform-create]');e=await evaluate(admin,()=>{const b=document.querySelector('[data-platform-merchant]');return{merchant:b.dataset.platformMerchant,outlet:b.dataset.initialOutlet,loginOutlet:Number(b.dataset.loginOutlet)}});check(e.merchant&&e.outlet&&e.loginOutlet);await evaluate(admin,()=>{const f=document.querySelector('[value=platform-owner-activate]').form;f.closest('details').open=true;f.elements.password.value='Synthetic-M06C-browser-owner-123';f.elements.confirmation.value='Synthetic-M06C-browser-owner-123';f.elements.confirm.checked=true});await submit(admin,'[value=platform-owner-activate]');check(await evaluate(admin,()=>document.querySelector('[data-platform-merchant]').innerText.includes('PROVISIONING')))});
 await test('Independent native owner saves setup while platform assigns trial and then activates demonstration',async()=>{eOwner=await context();await login(eOwner,{outlet:e.loginOutlet,username:'m06c-browser-owner',password:'Synthetic-M06C-browser-owner-123'});await open(eOwner,'http://127.0.0.1:8092/merchant.php?setup=1&merchant='+e.merchant);await evaluate(eOwner,()=>document.querySelector('#business-form [name=location]').value='Synthetic browser lab');await submit(eOwner,'#business-form');await evaluate(eOwner,()=>document.querySelector('#outlet-form [name=address]').value='Synthetic browser address');await submit(eOwner,'#outlet-form');await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');await evaluate(admin,m=>{const f=document.querySelector('[data-lifecycle-merchant="'+m+'"] form');f.closest('details').open=true;f.id='pa-lifecycle-form';f.elements.transition.value='start-trial';f.elements.choice.value='enterprise:3';f.elements.reason.value='Synthetic platform browser trial'},e.merchant);await submit(admin,'#pa-lifecycle-form');await change(e.merchant,'merchant-status',{status:'ACTIVE_DEMO'});check(await evaluate(admin,()=>document.querySelector('[data-platform-merchant]').innerText.includes('ACTIVE DEMO')));check(await evaluate(eOwner,async e=>(await fetch('/merchant.php?restaurant=1&merchant='+e.merchant+'&outlet='+e.outlet)).status,e)===200)});
 await test('Administrator UI changes reviewed outlet roles and native permission change is observable',async()=>{const d=meta.merchants.d;await change(d.merchant,'administrator',{user:secrets.m06c_operator.user,outlet:d.outlet,role:'outlet_administrator',active:'1'});await login(operator,{outlet:d.loginOutlet,username:secrets.m06c_operator.username,password:secrets.m06c_operator.password});await open(operator,'http://127.0.0.1:8092/merchant.php?seating=1&merchant='+d.merchant+'&outlet='+d.outlet);check(await evaluate(operator,()=>!!document.querySelector('[name=action][value=seating-floor]')));await change(d.merchant,'administrator',{user:secrets.m06c_operator.user,outlet:d.outlet,role:'cashier',active:'1'});await open(operator,'http://127.0.0.1:8092/merchant.php?seating=1&merchant='+d.merchant+'&outlet='+d.outlet);check(await evaluate(operator,()=>!document.querySelector('[name=action][value=seating-floor]')));check(await evaluate(operator,async a=>(await fetch('/merchant.php?seating=1&merchant='+a.merchant+'&outlet='+a.outlet)).status,meta.merchants.a)===403)});
 await test('Merchant suspend archive and restore preserve subscription and deny new direct access',async()=>{await change(e.merchant,'merchant-status',{status:'SUSPENDED'});await open(eOwner,'http://127.0.0.1:8092/merchant.php?subscription=1&merchant='+e.merchant+'&outlet='+e.outlet);check(await evaluate(eOwner,()=>!!document.querySelector('.subscription-admin-notice')&&document.querySelector('.subscription-features').innerText.includes('Restricted · administrative access')));check(await evaluate(eOwner,async e=>(await fetch('/merchant.php?restaurant=1&merchant='+e.merchant+'&outlet='+e.outlet)).status,e)===403);await change(e.merchant,'merchant-status',{status:'ARCHIVED'});await change(e.merchant,'merchant-status',{status:'ACTIVE_DEMO'});await open(admin,detail(e.merchant));check(await evaluate(admin,()=>document.body.innerText.includes('TRIAL')&&document.body.innerText.includes('merchant-status')))});
 await test('Real outlet creation and directory filtering retain CLOSED shop state',async()=>{await change(e.merchant,'outlet-create',{name:'Synthetic browser second outlet',opening:'09:00',closing:'21:00'});await open(admin,'http://127.0.0.1:8092/merchant.php?platform=outlets&q=Synthetic%20browser%20second%20outlet');check(await evaluate(admin,()=>document.body.innerText.includes('1 matching outlets')&&document.body.innerText.includes('CLOSED')))});
 await test('Desktop landscape portrait mobile console and merchant detail layouts remain readable without overflow',async()=>{for(const[width,height]of[[1440,900],[1280,800],[800,1280],[390,844]])for(const[section,name]of[['overview','overview'],['merchants','directory'],['detail&merchant='+e.merchant,'merchant']]){await open(admin,'http://127.0.0.1:8092/merchant.php?platform='+section);await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},admin);check(await evaluate(admin,()=>document.documentElement.scrollWidth<=innerWidth&&!/[\u00c2\u00c3]/.test(document.body.innerText)));await screenshot(admin,'m06c-'+name,width,height)}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m06c-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
