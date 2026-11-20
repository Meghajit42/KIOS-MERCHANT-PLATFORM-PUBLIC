'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M03ABrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M03CBrowserProfile'],
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
  const { targetId } = await call('Target.createTarget', { url: 'http://127.0.0.1:8092/merchant-dev.php', browserContextId });
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
    if (await evaluate(session, () => document.body.innerText.includes('Authorized dashboard'))) return;
    await delay(100);
  }
  throw new Error('Authenticated dashboard unavailable');
}
async function action(session, fields) {
  return evaluate(session, async fields => {
    const html = await (await fetch('/merchant-dev.php')).text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    fields._csrf = doc.querySelector('[name=_csrf]')?.value || '';
    const response = await fetch('/merchant-dev.php', { method: 'POST', body: new URLSearchParams(fields) });
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
 let v;for(let i=0;i<60;i++){try{v=await(await fetch('http://127.0.0.1:9229/json/version')).json();break;}catch{}await delay(100);}socket=new WebSocket(v.webSocketDebuggerUrl);
 await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve);socket.addEventListener('error',reject);});socket.addEventListener('message',e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(!p)return;clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);});
 const a=await context(),b=await context();await login(a,config.A);await login(b,config.B);
 const url=(f,key='design')=>'http://127.0.0.1:8092/merchant-dev.php?'+key+'=1&merchant='+f.receipt.merchant_id+'&outlet='+f.receipt.merchant_outlet_id;
 async function open(s,f,key='design',extra=''){await call('Page.navigate',{url:url(f,key)+extra},s);for(let i=0;i<80;i++){if(await evaluate(s,k=>!!document.querySelector(k==='design'?'[data-design-publication]':'#layout-form'),key))return;await delay(100);}throw new Error('Page unavailable');}
 async function submit(s,action){await evaluate(s,action=>document.querySelector('input[name=action][value="'+action+'"]').form.requestSubmit(),action);await delay(300);}
 let prior,published;
 await test('owner customizes saves previews and publishes design through native forms',async()=>{await open(a,config.A,'layout');await evaluate(a,()=>{const f=document.querySelector('#layout-form');f.elements.namedItem('cards').value='image';f.querySelector('[data-move=down]').click();f.requestSubmit();});await delay(300);await open(a,config.A);prior=await evaluate(a,()=>document.querySelector('[data-design-publication]').dataset.active);await submit(a,'design-publish');await open(a,config.A);published=await evaluate(a,()=>document.querySelector('[data-design-publication]').dataset.active);if(!published||published===prior)throw new Error('No new active version');});
 await test('draft changes do not leak into active design before publication',async()=>{await open(a,config.A,'layout');await evaluate(a,()=>{document.querySelector('[name=cards]').value='compact';document.querySelector('#layout-form').requestSubmit();});await delay(300);await open(a,config.A);if(!await evaluate(a,id=>document.querySelector('[data-design-publication]').dataset.active===id&&document.querySelector('.layout-preview').classList.contains('cards-image'),published))throw new Error('Draft leaked into active design');});
 await test('preview previous version then confirm restoration and reload',async()=>{await open(a,config.A,'design','&version_id='+prior);if(!await evaluate(a,id=>document.body.innerText.includes('Previewing selected version '+id),prior))throw new Error('Version preview missing');await submit(a,'design-restore');await open(a,config.A);if(await evaluate(a,()=>document.querySelector('[data-design-publication]').dataset.active)!==prior)throw new Error('Restore failed');});
 await test('B publishes independent green design and cannot access A versions or images',async()=>{await open(b,config.B);await submit(b,'design-publish');await open(b,config.B);if(!await evaluate(b,()=>document.querySelector('.restaurant-brand h2').textContent==='Green Counter'))throw new Error('B branding changed');for(const u of [url(config.A),url(config.B)+'&version_id='+published])if(await evaluate(b,async u=>(await fetch(u)).status,u)!==403)throw new Error('Foreign version accessible');const img=await evaluate(a,()=>document.querySelector('.restaurant-logo').getAttribute('src'));if(await evaluate(b,async u=>(await fetch(u)).status,img)!==403)throw new Error('Foreign image accessible');});
 await test('published compact cards load layout CSS and hide image wells',async()=>{await open(a,config.A);if(!await evaluate(a,()=>document.querySelector('.layout-preview').classList.contains('cards-compact')&&getComputedStyle(document.querySelector('.brand-photo')).display==='none'))throw new Error('Published card style not applied');});
 await test('CSRF unauthorized staff and forged publication fields denied',async()=>{if(await evaluate(a,async f=>(await fetch('/merchant-dev.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'design-publish',merchant:f.receipt.merchant_id,outlet:f.receipt.merchant_outlet_id,revision:'1',request:'0'.repeat(32)})})).status,config.A)!==400)throw new Error('CSRF accepted');const staff=await context();await login(staff,{username:'a-cashier',password:config.staffPassword,outlet:config.A.outlet});if(await evaluate(staff,async u=>(await fetch(u)).status,url(config.A))!==403)throw new Error('Staff access accepted');});
 await test('published preview responsive on desktop tablet and mobile',async()=>{await open(a,config.A);for(const [width,height] of [[1440,900],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);if(await evaluate(a,()=>document.documentElement.scrollWidth>innerWidth))throw new Error('Overflow');await screenshot(a,'m03c-publication',width,height);await evaluate(a,()=>document.querySelector('[data-brand-preview]').scrollIntoView());await screenshot(a,'m03c-active-preview',width,height);await evaluate(a,()=>scrollTo(0,0));}});
 }catch(e){output.failed++;output.cases.runner=e.message;}finally{fs.writeFileSync('C:/Mapped/Results/m03c-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close');}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0);}})();


