'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const meta=JSON.parse(fs.readFileSync('C:/KiosB0/seed-metadata.json','utf8')), secrets=JSON.parse(fs.readFileSync('C:/KiosB0/seed-input.json','utf8')); const config=Object.fromEntries(['a','b'].map(w=>[w,{outlet:meta.merchants[w].loginOutlet,username:secrets['owner_'+w+'_username'],password:secrets['owner_'+w+'_password'],receipt:{merchant_id:meta.merchants[w].merchant,merchant_outlet_id:meta.merchants[w].outlet}}]));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9229', '--user-data-dir=C:/M05BBrowserProfile'],
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
    if (await evaluate(session, () => document.querySelector('[data-cloud-dashboard],[data-seating]')!==null)) return;
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
 const a=await context(),b=await context();await login(a,config.a);await login(b,config.b);const url=w=>'http://127.0.0.1:8092/merchant.php?seating=1&merchant='+config[w].receipt.merchant_id+'&outlet='+config[w].receipt.merchant_outlet_id;
 async function open(s,w){await call('Page.navigate',{url:url(w)},s);await delay(500)}
 async function submit(s,op,data={},reservation=null){await evaluate(s,({op,data,reservation})=>{const area=reservation?document.querySelector('[data-reservation="'+reservation+'"]'):document;const form=area.querySelector('[value="seating-'+op+'"]').form;for(const [key,value]of Object.entries(data)){const input=form.elements.namedItem(key);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}form.requestSubmit();},{op,data,reservation});await delay(500)}
 const errors=[];socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text)});await call('Runtime.enable',{},a);let table,reservation;
 await test('Floor selection accessible keyboard and configured capacity',async()=>{await open(a,'a');check(await evaluate(a,()=>document.querySelectorAll('[data-table-select]').length===4));await evaluate(a,()=>{const t=document.querySelector('[data-table-select]');t.focus()});await call('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',windowsVirtualKeyCode:32},a);await call('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32},a);check(await evaluate(a,()=>!!document.querySelector('[data-table-select][aria-pressed=true]')));check(await evaluate(a,()=>{const f=document.querySelector('[data-floor-filter]');f.value=f.options[1].value;f.dispatchEvent(new Event('change'));return [...document.querySelectorAll('[data-table-select]')].filter(x=>!x.hidden).length===2;}));});
 await test('Native owner configures new persistent table',async()=>{await open(a,'a');await submit(a,'table',{name:'Browser T8',capacity:'8',type:'booth',active:'1',notes:'Synthetic browser table'});check(await evaluate(a,()=>document.querySelector('[role=status]').textContent.includes('saved')));table=await evaluate(a,()=>[...document.querySelectorAll('[data-table-select]')].find(x=>x.innerText.includes('Browser T8')).dataset.tableSelect);check(!!table)});
 await test('Quick reserve request confirm check-in seat complete releases table',async()=>{await evaluate(a,id=>document.querySelector('[data-table-select="'+id+'"]').click(),table);await evaluate(a,id=>document.querySelector('[data-reserve-table="'+id+'"]').click(),table);check(await evaluate(a,id=>document.querySelector('[value="seating-reserve"]').form.elements.table.value===id,table));await submit(a,'reserve',{guest:'Browser Guest',party:'4'});reservation=await evaluate(a,()=>[...document.querySelectorAll('[data-reservation]')].find(x=>x.innerText.includes('Browser Guest')).dataset.reservation);await submit(a,'transition',{status:'CONFIRMED'},reservation);await submit(a,'checkin',{},reservation);await submit(a,'transition',{status:'SEATED'},reservation);check(await evaluate(a,id=>document.querySelector('[data-table-select="'+id+'"]').innerText.includes('Occupied'),table));await submit(a,'transition',{status:'COMPLETED'},reservation);check(await evaluate(a,id=>document.querySelector('[data-table-select="'+id+'"]').innerText.includes('Available'),table));});
 await test('Overlapping confirmed booking gives clear error and no duplicate',async()=>{await submit(a,'reserve',{table,guest:'Confirmed Guest',party:'4'});const id=await evaluate(a,()=>[...document.querySelectorAll('[data-reservation]')].find(x=>x.innerText.includes('Confirmed Guest')).dataset.reservation);await submit(a,'transition',{status:'CONFIRMED'},id);await submit(a,'reserve',{table,guest:'Rejected Overlap',party:'4'});check(await evaluate(a,()=>!!document.querySelector('[role=alert]')&&!document.querySelector('#reservations').innerText.includes('Rejected Overlap')));await open(a,'a');await submit(a,'transition',{status:'CANCELLED'},id);});
 await test('Out-of-service operation and return to service',async()=>{await evaluate(a,id=>document.querySelector('[data-table-select="'+id+'"]').click(),table);await evaluate(a,id=>document.querySelector('[data-table-details="'+id+'"] details').open=true,table);await evaluate(a,({id})=>{const f=document.querySelector('[data-table-details="'+id+'"] form');f.elements.state.value='OUT_OF_SERVICE';f.elements.reason.value='Synthetic cleaning';f.requestSubmit()},{id:table});await delay(500);check(await evaluate(a,id=>document.querySelector('[data-table-select="'+id+'"]').innerText.includes('Out of service'),table));await evaluate(a,id=>{const f=document.querySelector('[data-table-details="'+id+'"] form');f.elements.state.value='AVAILABLE';f.elements.reason.value='Cleaning complete';f.requestSubmit()},table);await delay(500);});
 await test('Second restaurant independence and direct cross-tenant denial',async()=>{await open(b,'b');check(await evaluate(b,()=>document.querySelectorAll('[data-table-select]').length===0));check(await evaluate(b,async u=>(await fetch(u)).status,url('a'))===403);await submit(b,'floor',{name:'B Dining',active:'1'});await submit(b,'table',{name:'B Table',capacity:'4'});check(await evaluate(b,()=>document.body.innerText.includes('B Table')&&!document.body.innerText.includes('Browser T8')));await open(a,'a');check(await evaluate(a,()=>!document.body.innerText.includes('B Table')))});
 await test('Native cashier manager kitchen sessions enforce scoped roles',async()=>{const staff=JSON.parse(fs.readFileSync('C:/Mapped/Results/m05b-staff.json','utf8'));for(const role of ['cashier','outlet_administrator','kitchen']){const session=await context();if(role==='kitchen'){await evaluate(session,c=>{const f=document.querySelector('form');f.elements.login_outlet.value=c.outlet;f.elements.username.value=c.username;f.elements.password.value=c.password;f.submit()},staff[role]);await delay(500);check(await evaluate(session,async u=>(await fetch(u)).status,url('a'))===403);}else{await login(session,staff[role]);await open(session,'a');check(await evaluate(session,()=>!!document.querySelector('[data-seating]')));check(await evaluate(session,()=>!!document.querySelector('#configuration'))===(role==='outlet_administrator'));if(role==='cashier'){await submit(session,'reserve',{table,guest:'Cashier Guest',party:'2'});check(await evaluate(session,()=>document.querySelector('#reservations').innerText.includes('Cashier Guest')));}}}});
 await test('CSRF and duplicate-submit guard with persistent history',async()=>{check(await evaluate(a,async()=> (await fetch('/merchant.php',{method:'POST',body:new URLSearchParams({_csrf:'bad',action:'seating-floor'})})).status)===400,'CSRF response');await open(a,'a');check(await evaluate(a,()=>{const form=document.querySelector('[value="seating-reserve"]').form;const first=new Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(first);const second=new Event('submit',{bubbles:true,cancelable:true});form.dispatchEvent(second);return second.defaultPrevented&&form.querySelector('button').disabled;}),'Duplicate event was not prevented');await open(a,'a');check(await evaluate(a,()=>document.querySelector('#seating-history').innerText.toLowerCase().includes('transition')),'Transition missing from persisted history');});
 await test('Desktop landscape portrait mobile layouts readable and overflow-free',async()=>{for(const [width,height]of [[1440,900],[1280,800],[800,1280],[390,844]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},a);await delay(100);check(await evaluate(a,()=>document.documentElement.scrollWidth<=innerWidth));check(await evaluate(a,()=>[...document.querySelectorAll('[data-table-select]')].every(b=>b.getBoundingClientRect().height>=44)));await evaluate(a,()=>document.querySelector('#floor-workspace').scrollIntoView({block:'start'}));await screenshot(a,'m05b-floor',width,height)}});
 await test('No browser runtime exceptions and public commerce absent',async()=>{check(errors.length===0);check(await evaluate(a,()=>!document.querySelector('[value=checkout],[value=pay]')&&document.body.innerText.includes('Ordering is closed')))});
 }catch(e){output.failed++;output.cases.runner=e.message}finally{fs.writeFileSync('C:/Mapped/Results/m05b-browser-result.json',JSON.stringify(output,null,2));try{await call('Browser.close')}catch{}socket?.close();edge.kill();process.exit(output.failed?1:0)}})();
