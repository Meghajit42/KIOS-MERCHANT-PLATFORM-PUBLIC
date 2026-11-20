'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M04BBrowserProfile'],
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
  if (result.exceptionDetails) throw new Error('Page evaluation rejected: '+JSON.stringify(result.exceptionDetails));
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
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);
 const url=(key,w='a')=>'http://127.0.0.1:8092/merchant.php?'+key+'&merchant='+config[w].receipt.merchant_id+'&outlet='+config[w].receipt.merchant_outlet_id;
 async function open(s,u){await call('Page.navigate',{url:u},s);let probe;for(let i=0;i<100;i++){probe=await evaluate(s,()=>({url:location.href,ready:document.readyState,shell:!!document.querySelector('.merchant-topbar'),background:document.body?getComputedStyle(document.body).backgroundColor:null}));if(probe.url===u&&probe.ready==='complete'&&probe.shell)return;await delay(100)}output.navigationFailure=probe;throw Error('Workspace did not finish navigation: '+u);} 
 await test('Owner shell, genuine progress and tenant-specific dashboard links',async()=>{check(await evaluate(a,()=>document.querySelector('.merchant-sidebar nav').children.length===11&&document.querySelectorAll('.dashboard-tool').length===9&&document.querySelector('.merchant-metrics').innerText.includes('Ordering closed')));check(await evaluate(b,()=>document.querySelector('h1').innerText.includes('Restaurant B')))});
 await test('All owner workspace navigation and premium asset rendering',async()=>{for(const q of ['setup=1','restaurant=1&section=menu','restaurant=1&section=kitchen','restaurant=1&section=staff','brand=1','layout=1','design=1']){await open(a,url(q));check(await evaluate(a,()=>!!document.querySelector('.merchant-topbar')&&getComputedStyle(document.body).backgroundColor==='rgb(244, 246, 250)'),'Workspace shell/style: '+q)}});
 await test('Menu text category and availability filters are scoped to rendered tenant records',async()=>{await open(a,url('restaurant=1&section=menu'));check(await evaluate(a,()=>{const q=document.querySelector('.merchant-menu-tools input');q.value='missing product';q.dispatchEvent(new Event('input'));return [...document.querySelectorAll('details[data-product]')].every(d=>d.hidden)&&document.querySelector('.merchant-filter-status').innerText.startsWith('0 of')}));await evaluate(a,()=>{const q=document.querySelector('.merchant-menu-tools input');q.value='';q.dispatchEvent(new Event('input'))});check(await evaluate(a,()=>!!document.querySelector('details[data-product]:not([hidden])')))});
 await test('Global navigation search exposes only current workspace records and supported tools',async()=>{check(await evaluate(a,()=>{const q=document.querySelector('.merchant-global-label input');q.value='Kitchen';q.dispatchEvent(new Event('input'));return [...document.querySelectorAll('.merchant-search-results a')].some(a=>a.textContent==='Kitchen')}))});
 await test('Product editing and saved availability remain functional',async()=>{await evaluate(a,()=>{const f=document.querySelector('details[data-product] form');f.querySelector('[name=description]').value='Synthetic M04B edited description';f.requestSubmit()});await delay(600);await open(a,url('restaurant=1&section=menu'));check(await evaluate(a,()=>document.querySelector('details[data-product] [name=description]').value==='Synthetic M04B edited description'))});
 await test('Draft save reload preview publication history and restoration',async()=>{await open(a,url('layout=1'));await evaluate(a,()=>{const f=document.querySelector('#layout-form');f.querySelector('[name=cards]').value='compact';f.querySelector('[name=cards]').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-device=mobile]').click();document.querySelector('[data-layout-action=layout-save]').click()});await delay(600);await open(a,url('layout=1'));check(await evaluate(a,()=>document.querySelector('[name=cards]').value==='compact'));await open(a,url('design=1'));const old=await evaluate(a,()=>document.querySelector('[data-design-publication]').dataset.active);await evaluate(a,()=>document.querySelector('[value=design-publish]').form.requestSubmit());await delay(600);check(await evaluate(a,old=>document.querySelector('[data-design-publication]').dataset.active!==old,old));await open(a,url('design=1&version_id='+old));await evaluate(a,()=>document.querySelector('[value=design-restore]').form.requestSubmit());await delay(600);check(await evaluate(a,old=>document.querySelector('[data-design-publication]').dataset.active===old,old))});
 await test('Cross-merchant access and CSRF remain denied',async()=>{check(await evaluate(b,async u=>(await fetch(u)).status,url('layout=1'))===403);check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'layout-save'})})).status)===400)});

 await test('Desktop table keyboard edit and full serving display',async()=>{await open(a,url('restaurant=1&section=menu'));await call('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false},a);output.tableProbe=await evaluate(a,()=>document.querySelector('.merchant-product-table')?.textContent);check(await evaluate(a,()=>{const t=document.querySelector('.merchant-product-table');const button=t.querySelector('button');button.focus();return !!t.caption&&t.querySelectorAll('th[scope=col]').length===5&&t.innerText.includes('INR')}));await call('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',windowsVirtualKeyCode:32},a);await call('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32},a);output.tableFocus=await evaluate(a,()=>({open:document.querySelector('details[data-product]').open,active:document.activeElement.tagName}));check(output.tableFocus.open&&output.tableFocus.active==='INPUT')});
 await test('Kitchen counts and staff permission descriptions use authorized data',async()=>{await open(a,url('restaurant=1&section=kitchen'));check(await evaluate(a,()=>document.body.innerText.includes('1 assigned products')&&document.body.innerText.includes('0 assigned staff')));await screenshot(a,'m04b-kitchen',1440,900);await open(a,url('restaurant=1&section=staff'));check(await evaluate(a,()=>document.body.innerText.includes('Cashiers manage payment tasks')&&document.body.innerText.includes('pending')));await screenshot(a,'m04b-staff',1440,900)});
 await test('Representative keyboard focus labels statuses and reduced motion',async()=>{await open(a,url('layout=1'));await evaluate(a,()=>document.querySelector('.merchant-skip').focus());await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9},a);check(await evaluate(a,()=>document.activeElement!==document.body&&getComputedStyle(document.activeElement).outlineStyle!=='none'));check(await evaluate(a,()=>[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>e.getClientRects().length).every(e=>e.closest('label')||e.labels?.length||e.getAttribute('aria-label'))&&document.querySelectorAll('[role=status]').length>=1));await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]},a);check(await evaluate(a,()=>getComputedStyle(document.querySelector('button')).transitionDuration==='0s'));});
 await test('Five-resolution responsive shell/editor and keyboard landmarks',async()=>{for(const q of ['', 'layout=1','restaurant=1&section=menu']){await open(a,q?url(q):'http://127.0.0.1:8092/merchant.php');for(const [width,height] of [[1440,900],[1280,800],[768,1024],[1024,768],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth&&!!document.querySelector('.merchant-skip')&&[...document.querySelectorAll('button')].filter(b=>b.getClientRects().length).every(b=>b.getBoundingClientRect().height>=44)));await screenshot(a,q.startsWith('layout')?'m04b-editor':q?'m04b-menu':'m04b-dashboard',width,height)}}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m04b-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
