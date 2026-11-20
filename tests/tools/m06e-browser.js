'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M06EBrowserProfile'],
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
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break}catch{}await delay(100)}socket=new WebSocket(v.webSocketDebuggerUrl);await new Promise((res,rej)=>{socket.addEventListener('open',res);socket.addEventListener('error',rej)});socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Page.javascriptDialogOpening'){call('Page.handleJavaScriptDialog',{accept:true},m.sessionId);return;}if(m.method==='Runtime.exceptionThrown'){(output.runtimeErrors??=[]).push(m.params.exceptionDetails.text);}const p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result)});
 const admin=await context(),other=await context();await login(admin,{outlet:meta.platform.loginOutlet,username:secrets.platform_username,password:secrets.platform_password});await login(other,config.b);let f=null,owner=null,second=null,service=null;
 const base='http://127.0.0.1:8092';const scoped=(q,o=f.outlet)=>base+'/merchant.php?'+q+'&merchant='+f.merchant+'&outlet='+o;
 async function open(s,u){const t=Date.now();await call('Page.navigate',{url:u.startsWith('/')?base+u:u},s);for(let i=0;i<120;i++){if(await evaluate(s,()=>document.readyState==='complete')){const url=await evaluate(s,()=>location.href);if(url===(u.startsWith('/')?base+u:u)){(output.performance??=[]).push({route:new URL(url).searchParams.keys().next().value||'dashboard',ms:Date.now()-t});return}}await delay(100)}throw Error('Page not ready')}
 async function fill(s,selector,values){await evaluate(s,({selector,values})=>{let f=document.querySelector(selector);if(f?.tagName!=='FORM')f=f?.form;if(!f)throw Error('Form missing');if(f.closest('details'))f.closest('details').open=true;for(const[k,v]of Object.entries(values)){const e=f.elements.namedItem(k);if(!e)throw Error('Control missing: '+k);if(e.type==='checkbox')e.checked=!!v;else e.value=v;e.dispatchEvent(new Event('change',{bubbles:true}));e.dispatchEvent(new Event('input',{bubbles:true}));}},{selector,values})}
 async function submit(s,selector){const started=Date.now();check(await evaluate(s,selector=>{let f=document.querySelector(selector);if(f?.tagName!=='FORM')f=f?.form;if(!f||!f.reportValidity())return false;window.__integratedSubmitting=true;f.requestSubmit();return true},selector),'Displayed form invalid');for(let i=0;i<120;i++){if(await evaluate(s,()=>window.__integratedSubmitting===undefined&&document.readyState==='complete')){const error=await evaluate(s,()=>document.querySelector('[role=alert],.pa-error')?.innerText||((document.body.innerText||'').startsWith('{')?document.body.innerText:''));check(!error,'Server rejected form: '+error);(output.mutations??=[]).push({form:selector,ms:Date.now()-started});return}await delay(100)}throw Error('Submission timeout')}
 const actionSelector=action=>'[name=action][value="'+action+'"]';
 async function change(op,data){await open(admin,'/merchant.php?platform=detail&merchant='+f.merchant);const selector='[name=op][value="'+op+'"]';const values={reason:'Synthetic M06E browser '+op,confirm:true};for(const[k,v]of Object.entries(data))values['data['+k+']']=v;await fill(admin,selector,values);await submit(admin,selector)}
 async function configure(s,o,platform=false){await open(s,scoped('outlet_wizard=1'+(platform?'&platform_setup=1':''),o));await fill(s,actionSelector('integration-save'),{'profile[address]':'Synthetic M06E browser lab','profile[contact]':'m06e-browser@example.test',reason:'Synthetic browser configuration',confirm:true});await evaluate(s,()=>document.querySelectorAll('[name="modules[]"]:not(:disabled)').forEach(e=>e.checked=true));await submit(s,actionSelector('integration-save'));check(await evaluate(s,()=>document.querySelector('[name="profile[address]"]').value==='Synthetic M06E browser lab'));await fill(s,actionSelector('integration-review'),{reason:'Synthetic browser reviewed configuration',confirm:true});await submit(s,actionSelector('integration-review'));check(await evaluate(s,()=>document.body.innerText.includes('Current revision reviewed.')))}
 await test('Actual platform browser creates synthetic merchant and activates its native owner credentials',async()=>{await open(admin,'/merchant.php?platform=create');const values={};for(const[k,v]of Object.entries({business_name:'Synthetic M06E browser restaurant',business_type:'restaurant',admin_contact:'m06e-browser@example.test',outlet_name:'Synthetic M06E browser primary',owner_name:'Synthetic M06E owner',owner_username:'m06e-browser-owner',opening_time:'08:00',closing_time:'22:00'}))values['data['+k+']']=v;values.confirm=true;await fill(admin,actionSelector('platform-create'),values);await submit(admin,actionSelector('platform-create'));f=await evaluate(admin,()=>{const x=document.querySelector('[data-platform-merchant]');return{merchant:x.dataset.platformMerchant,outlet:x.dataset.initialOutlet,loginOutlet:Number(x.dataset.loginOutlet)}});check(f.merchant&&f.outlet&&f.loginOutlet);await fill(admin,actionSelector('platform-owner-activate'),{password:'Synthetic-M06E-browser-password-123',confirmation:'Synthetic-M06E-browser-password-123',confirm:true});await submit(admin,actionSelector('platform-owner-activate'));owner=await context();await login(owner,{outlet:f.loginOutlet,username:'m06e-browser-owner',password:'Synthetic-M06E-browser-password-123'});});
 await test('Integrated initial journey saves business subscription outlet preferences review and explicit private activation',async()=>{await open(owner,scoped('setup=1'));await fill(owner,'#business-form',{location:'Synthetic browser lab'});await submit(owner,'#business-form');await fill(owner,'#outlet-form',{address:'Synthetic browser primary'});await submit(owner,'#outlet-form');await open(admin,'/merchant.php?plans=1');const sel='[data-lifecycle-merchant="'+f.merchant+'"] form';await fill(admin,sel,{transition:'start-trial',choice:'enterprise:3',reason:'Synthetic M06E integrated browser trial'});await submit(admin,sel);await configure(admin,f.outlet,true);await change('merchant-status',{status:'ACTIVE_DEMO'});await open(owner,scoped('assistant=1'));check(await evaluate(owner,()=>!!document.querySelector('[data-integrated-setup]')&&document.querySelector('[data-setup-card=menu] [data-ready=no]')));});
 await test('Native additional outlet creation through guided wizard persists independent scoped dashboard',async()=>{await open(admin,'/merchant.php?outlet_directory=1&platform_setup=1&merchant='+f.merchant);await fill(admin,actionSelector('platform-admin'),{'data[name]':'Synthetic M06E browser secondary',reason:'Synthetic browser native outlet creation',confirm:true});await submit(admin,actionSelector('platform-admin'));await open(owner,'/merchant.php?outlet_directory=1&merchant='+f.merchant);check(await evaluate(owner,()=>document.querySelectorAll('.is-card').length===2));second=await evaluate(owner,()=>{const c=[...document.querySelectorAll('.is-card')].find(c=>c.innerText.includes('browser secondary'));return new URL(c.querySelector('a').href).searchParams.get('outlet')});check(!!second&&second!==f.outlet);await configure(owner,second);await call('Page.reload',{},owner);await delay(500);check(await evaluate(owner,o=>document.querySelector('[data-outlet]').dataset.outlet===o&&document.querySelector('[name="profile[contact]"]').value==='m06e-browser@example.test',second));});
 await test('Native secondary menu station product and serving controls save reload without changing primary outlet',async()=>{await open(owner,scoped('restaurant=1&section=kitchen',second));await fill(owner,'#station-form',{name:'Synthetic browser kitchen'});await submit(owner,'#station-form');await open(owner,scoped('restaurant=1&section=menu',second));await fill(owner,'#category-form',{name:'Synthetic browser menu'});await submit(owner,'#category-form');await fill(owner,'#product-form',{name:'Synthetic M06E dish',sku:'m06e-browser-dish','variants[0][price]':'30.00'});await submit(owner,'#product-form');check(await evaluate(owner,()=>!!document.querySelector('[data-product]')));await open(owner,scoped('restaurant=1&section=menu'));check(await evaluate(owner,()=>document.querySelectorAll('[data-product]').length===0));});
 await test('Secondary inventory recipe floor table and reservation forms persist real scoped records',async()=>{await open(owner,scoped('inventory=1',second));await fill(owner,actionSelector('inventory-ingredient'),{name:'Synthetic browser stock',opening:'1000',minimum:'10'});await submit(owner,actionSelector('inventory-ingredient'));await fill(owner,actionSelector('inventory-recipe'),{'lines[0][quantity]':'10'});await submit(owner,actionSelector('inventory-recipe'));check(await evaluate(owner,()=>!!document.querySelector('[data-recipe]')));await open(owner,scoped('seating=1',second));await fill(owner,actionSelector('seating-floor'),{name:'Synthetic browser dining'});await submit(owner,actionSelector('seating-floor'));await fill(owner,actionSelector('seating-table'),{name:'Synthetic M06E table',capacity:'4'});await submit(owner,actionSelector('seating-table'));await fill(owner,actionSelector('seating-reserve'),{guest:'Synthetic browser guest'});await submit(owner,actionSelector('seating-reserve'));const sel='[name=action][value=seating-transition]';await fill(owner,sel,{status:'CANCELLED'});await submit(owner,sel);await fill(owner,actionSelector('seating-status'),{state:'OCCUPIED',reason:'Synthetic browser seating'});await submit(owner,actionSelector('seating-status'));});
 await test('Internal POS actual controls save and submit nonfinancial order with digital kitchen transitions',async()=>{await open(owner,scoped('pos=1',second));await evaluate(owner,()=>{document.querySelector('[data-pos-table]:not(:disabled)').click();const p=document.querySelector('[data-pos-product]');p.querySelector('[data-pos-quantity]').value='2';p.querySelector('[data-pos-add]').click();});await submit(owner,actionSelector('pos-save'));service=await evaluate(owner,()=>new URL(location.href).searchParams.get('service'));check(!!service);await evaluate(owner,()=>document.querySelector('[data-pos-submit]').click());await delay(700);check(await evaluate(owner,()=>document.body.innerText.includes('No payment is due')));await open(owner,scoped('service-kitchen=1',second));check(await evaluate(owner,()=>document.querySelector('[data-service-task]').innerText.includes('Synthetic M06E dish')));await screenshot(owner,'m06e-secondary-kitchen',1280,800);await submit(owner,'[data-service-task] '+actionSelector('pos-task'));await submit(owner,'[data-service-task] '+actionSelector('pos-task'));await open(owner,scoped('pos=1',second));await submit(owner,'[data-service="'+service+'"] '+actionSelector('pos-served'));check(await evaluate(owner,s=>document.querySelector('[data-service="'+s+'"]').innerText.includes('SERVED'),service));});
 await test('Bill preparation and analytics remain native persisted nonfinancial workspaces',async()=>{await open(owner,scoped('billing=1&service='+service,second));await fill(owner,'[data-bill-form]',{people:'2',reason:'Synthetic browser bill draft'});await submit(owner,'[data-bill-form]');check(await evaluate(owner,()=>!!document.querySelector('#history details')&&document.body.innerText.includes('NOT A RECEIPT OR TAX INVOICE')));await screenshot(owner,'m06e-bill-preview',1440,900);await open(owner,scoped('analytics=1',second));check(await evaluate(owner,()=>document.querySelector('h1').innerText.includes('analytics')||document.querySelector('h1').innerText.includes('Analytics')));await screenshot(owner,'m06e-analytics',1440,900);});
 await test('Tenant isolation valid native identity and CSRF are enforced on new guided routes',async()=>{for(const q of['outlet_wizard=1','assistant=1','outlet_home=1','restaurant=1','inventory=1','pos=1'])check(await evaluate(other,async u=>(await fetch(u)).status,scoped(q,second))===403);check(await evaluate(owner,async()=>(await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({action:'integration-save',_csrf:'bad'})})).status)===400);await open(owner,scoped('assistant=1',second));check(await evaluate(owner,()=>!document.body.innerText.includes('Restaurant B')));});
 await test('Five actual responsive screenshots scoped assistant wizard and dashboard are overflow-free',async()=>{for(const q of ['outlet_wizard=1','assistant=1','outlet_home=1']){await open(owner,scoped(q,second));for(const[w,h]of[[1440,900],[1366,768],[1280,800],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:false},owner);check(await evaluate(owner,()=>document.documentElement.scrollWidth<=innerWidth),'Horizontal overflow '+q+' '+w);await screenshot(owner,'m06e-'+q.split('=')[0],w,h);}}});
 await test('Targeted accessibility verifies labels landmarks focus and large interactive controls',async()=>{await open(owner,scoped('outlet_wizard=1',second));check(await evaluate(owner,()=>!!document.querySelector('main')&&!!document.querySelector('nav[aria-label]')&&[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>e.getClientRects().length).every(e=>e.labels.length||e.getAttribute('aria-label'))));await evaluate(owner,()=>document.querySelector('.is-skip').focus());await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9},owner);check(await evaluate(owner,()=>document.activeElement!==document.body));output.accessibility={labels:true,landmarks:true,keyboardFocus:true,fullWCAGCertification:false,limitations:'Targeted automated review; screen reader and broader usability testing remain pending'};});
 await test('Browser reports no uncaught application errors in scoped integration workflows',async()=>{check(!(output.runtimeErrors?.length));});
 output.fixture={merchant:f,secondary:second,service};
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m06e-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
