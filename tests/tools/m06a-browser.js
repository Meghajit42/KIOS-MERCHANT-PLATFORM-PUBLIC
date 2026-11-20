'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M06ABrowserProfile'],
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
 const a=await context(),b=await context(),c=await context(),admin=await context();await login(a,config.a);await login(b,config.b);await login(c,{outlet:meta.merchants.c.loginOutlet,username:secrets.owner_c_username,password:secrets.owner_c_password});
 await evaluate(admin,()=>{document.querySelector('[name=login_outlet]').value='1'});await login(admin,{outlet:meta.platform.loginOutlet,username:secrets.platform_username,password:secrets.platform_password});
 const url=(w,key='subscription')=>'http://127.0.0.1:8092/merchant.php?'+key+'=1&merchant='+meta.merchants[w].merchant+'&outlet='+meta.merchants[w].outlet;
 async function open(s,u){await call('Page.navigate',{url:u},s);for(let i=0;i<100;i++){if(await evaluate(s,u=>location.href===u&&document.readyState==='complete',u))return;await delay(100)}throw Error('Page readiness timeout')}
 async function saved(s,operation){await evaluate(s,op=>{document.querySelector('[name=action][value="'+op+'"]').form.querySelector('button').click()},operation);for(let i=0;i<100;i++){if(await evaluate(s,()=>location.search.includes('saved=1')&&document.readyState==='complete'))return;await delay(100)}throw Error('Save did not succeed')}
 await test('Three independent native merchant sessions show their assigned plan feature status',async()=>{for(const[s,w,name]of[[a,'a','Enterprise'],[b,'b','Growth'],[c,'c','Starter']]){await open(s,url(w));check(await evaluate(s,n=>document.querySelector('#status').innerText.includes(n)&&document.querySelector('#status').innerText.includes('ACTIVE'),name))}});
 await test('Platform interface protected against owner access and merchant assignment escalation',async()=>{check(await evaluate(a,async()=> (await fetch('/merchant.php?plans=1')).status)===403);check(await evaluate(c,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'plans-assign'})})).status)===400)});
 await test('Native platform creates and versions a configured demonstration plan',async()=>{await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');await evaluate(admin,()=>{const f=document.querySelector('[value=plans-save]').form;f.elements.plan_id.value='browser-plan';f.elements.name.value='Browser demonstration';f.elements.description.value='Synthetic menu-only plan, no charges';f.querySelector('[name="features[]"][value=menu]').checked=true;for(const k of['outlets','products','staff','tables'])f.elements['limits['+k+']'].value='5'});await saved(admin,'plans-save');check(await evaluate(admin,()=>document.body.innerText.includes('Browser demonstration')));await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1&plan_id=browser-plan');check(await evaluate(admin,()=>document.querySelector('[value=plans-save]').form.elements.version.value==='1'));await evaluate(admin,()=>document.querySelector('[value=plans-save]').form.elements.description.value='Versioned synthetic metadata only');await saved(admin,'plans-save')});
 await test('Platform authenticated assignment save immutable history and owner refresh',async()=>{await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');await evaluate(admin,c=>{const f=document.querySelector('[value=plans-assign]').form;f.elements.merchant.value=c.merchant;const text=f.elements.merchant.selectedOptions[0].textContent;f.elements.expected.value=text.match(/revision (\d+)/)[1];f.elements.choice.value='starter:1';f.elements.reason.value='Attended synthetic browser assignment'},meta.merchants.c);await saved(admin,'plans-assign');await open(c,url('c'));check(await evaluate(c,()=>document.querySelector('#history').innerText.includes('Attended synthetic browser assignment')))});
 await test('Disabled features absent on dashboard and denied through direct API and export URLs',async()=>{await open(c,url('c'));check(await evaluate(c,()=>document.body.hasAttribute('data-entitlements')&&!document.querySelector('.merchant-sidebar a[href*="inventory=1"]'))); await open(c,'http://127.0.0.1:8092/merchant.php');check(await evaluate(c,()=>![...document.querySelectorAll('.dashboard-tool')].some(x=>x.href.includes('inventory=1')||x.href.includes('analytics=1')||x.href.includes('billing=1'))));check(await evaluate(c,async u=>(await fetch(u)).status,url('c','inventory'))===403);check(await evaluate(b,async u=>(await fetch(u+'&export=csv')).status,url('b','analytics'))===403);check(await evaluate(c,async u=>(await fetch(u)).status,url('a'))===403)});
 await test('Desktop tablet portrait mobile subscription and platform layouts',async()=>{await open(a,url('a'));check(await evaluate(a,()=>document.querySelector('#comparison').innerText.includes('outlets')&&!document.querySelector('#comparison').innerText.includes('{"outlets"'))); await open(a,url('a'));await open(admin,'http://127.0.0.1:8092/merchant.php?plans=1');for(const[width,height]of[[1440,900],[1280,800],[800,1280],[390,844]]){for(const[s,name]of[[a,'merchant'],[admin,'platform']]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},s);check(await evaluate(s,()=>document.documentElement.scrollWidth<=innerWidth));await screenshot(s,'m06a-'+name,width,height)}}});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m06a-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
