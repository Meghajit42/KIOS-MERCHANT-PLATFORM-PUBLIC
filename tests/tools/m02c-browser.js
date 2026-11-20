'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M02CBrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9226', '--user-data-dir=C:/M02CBrowserProfile'],
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
  const { targetId } = await call('Target.createTarget', { url: 'http://127.0.0.1:8089/merchant-dev.php', browserContextId });
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
output.responses=[];output.layouts=[];output.runtimeExceptions=0;
const scopeOf=f=>({merchant:f.receipt.merchant_id,outlet:f.receipt.merchant_outlet_id});
function url(scope,section='menu'){return 'http://127.0.0.1:8089/merchant-dev.php?restaurant=1&merchant='+scope.merchant+'&outlet='+scope.outlet+'&section='+section;}
async function waitFor(session,fn){for(let i=0;i<100;i++){if(await evaluate(session,fn))return;await delay(100);}throw new Error('Expected restaurant page unavailable');}
async function navigate(session,scope,section='menu'){await call('Page.navigate',{url:url(scope,section)},session);await waitFor(session,()=>!!document.querySelector('[data-restaurant]'));}
async function perform(session,scope,op,data){
  const response=await evaluate(session,async({scope,op,data})=>{
    const html=await(await fetch('/merchant-dev.php?restaurant=1&merchant='+scope.merchant+'&outlet='+scope.outlet)).text();
    const doc=new DOMParser().parseFromString(html,'text/html');const root=doc.querySelector('[data-restaurant]');
    if(!root)return {status:403};
    const params=new URLSearchParams({_csrf:doc.querySelector('[name=_csrf]').value,action:'restaurant-'+op,...scope,version:root.dataset.version,request:crypto.randomUUID().replaceAll('-','')});
    function add(key,value){if(Array.isArray(value))value.forEach((v,i)=>add(key+'['+i+']',v));else if(value&&typeof value==='object')Object.entries(value).forEach(([k,v])=>add(key+'['+k+']',v));else params.append(key,String(value));}
    Object.entries(data).forEach(([k,v])=>add(k,v));const r=await fetch('/merchant-dev.php',{method:'POST',body:params});return {status:r.status,body:JSON.parse(await r.text())};
  },{scope,op,data});
  output.responses.push({operation:op,status:response.status});
  if(response.status!==200)throw new Error('Configuration request failed: '+op+' HTTP '+response.status);
  return response.body.data.result;
}
async function denied(session,scope,op,data){return evaluate(session,async({scope,op,data})=>{
  const doc=new DOMParser().parseFromString(await(await fetch('/merchant-dev.php')).text(),'text/html');
  const p=new URLSearchParams({_csrf:doc.querySelector('[name=_csrf]')?.value||'',action:'restaurant-'+op,...scope,version:'1',request:crypto.randomUUID().replaceAll('-','')});
  for(const [k,v]of Object.entries(data))p.append(k,String(v));return(await fetch('/merchant-dev.php',{method:'POST',body:p})).status;
},{scope,op,data});}
function product(cat,station,index,sku){return {id:0,name:['Synthetic Tea','Synthetic Coffee','Synthetic Sandwich','Synthetic Cake','Synthetic Juice'][index],description:'Synthetic browser product',sku:sku||'A-PRODUCT-'+index,category:cat,station,available:1,variants:[{id:0,label:'Regular',unit:'portion',quantity:1,price:String(20+index*10)+'.00'},{id:0,label:'Large',unit:'portion',quantity:2,price:String(35+index*10)+'.00'}]};}
(async()=>{
  try{
    let version;for(let i=0;i<60;i++){try{version=await(await fetch('http://127.0.0.1:9226/json/version')).json();break;}catch{}await delay(100);}
    if(!version)throw new Error('Guest browser unavailable');output.browser=version.Browser;socket=new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve);socket.addEventListener('error',reject);});
    socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.method==='Runtime.exceptionThrown')output.runtimeExceptions++;const r=pending.get(m.id);if(!r)return;clearTimeout(r.timer);pending.delete(m.id);if(m.error)r.reject(new Error(m.error.message));else r.resolve(m.result);});
    const a=await context(),b=await context();await call('Runtime.enable',{},a);await call('Runtime.enable',{},b);
    const fa=config.fixtures.browserA,fb=config.fixtures.browserB,sa=scopeOf(fa),sb=scopeOf(fb);
    const cats=[],stations=[],products=[],staff=[];let bcat,bstation,bproduct;
    await test('two owners authenticate into independent empty restaurant dashboards',async()=>{await login(a,fa);await login(b,fb);await navigate(a,sa);await navigate(b,sb);check(await evaluate(a,id=>document.querySelector('[data-restaurant]').dataset.merchant===id,sa.merchant));});
    await test('restaurant A creates three categories and two named supported stations through the browser',async()=>{
      // First category is a normal form submission; remaining mutations use real authenticated browser HTTP requests.
      await evaluate(a,()=>{document.querySelector('#category-form [name=name]').value='Synthetic Drinks';document.querySelector('#category-form [name=sort]').value='0';document.querySelector('#category-form').requestSubmit();});
      await waitFor(a,()=>!!document.querySelector('[role=status]'));
      cats.push(Number(await evaluate(a,()=>document.querySelector('[data-category]').dataset.category)));
      for(const [index,name]of ['Synthetic Snacks','Synthetic Desserts'].entries())cats.push((await perform(a,sa,'category',{id:0,name,sort:index+1})).categoryId);
      stations.push((await perform(a,sa,'station',{id:0,code:'BEVERAGE',name:'Synthetic Beverage Counter',department:'GROUND_FLOOR',active:1})).stationId);
      stations.push((await perform(a,sa,'station',{id:0,code:'GENERAL_KITCHEN',name:'Synthetic Main Kitchen',department:'FIRST_FLOOR_KITCHEN',active:1})).stationId);
    });
    await test('A configures five products multiple serving prices and reusable SKUs',async()=>{
      for(let i=0;i<5;i++)products.push((await perform(a,sa,'product',product(cats[i%3],stations[i%2],i,i===0?'SHARED-TEA':undefined))).productId);
      await navigate(a,sa,'menu');check(await evaluate(a,()=>document.querySelectorAll('[data-product]').length===5&&document.querySelectorAll('[data-category]').length===3));
      await screenshot(a,'m02c-menu-setup',1280,800);
    });
    await test('B creates its own categories station price and overlapping SKU',async()=>{
      bcat=(await perform(b,sb,'category',{id:0,name:'Synthetic Beta Menu',sort:0})).categoryId;
      bstation=(await perform(b,sb,'station',{id:0,code:'SWEETS',name:'Synthetic Beta Sweet Counter',department:'GROUND_FLOOR',active:1})).stationId;
      const d=product(bcat,bstation,0,'SHARED-TEA');d.variants[0].price='55.00';bproduct=(await perform(b,sb,'product',d)).productId;
      await navigate(b,sb,'preview');check(await evaluate(b,()=>document.body.innerText.includes('₹55.00')&&document.querySelectorAll('[data-preview-product]').length===1));
      check(bproduct!==products[0]);await screenshot(b,'m02c-beta-preview',800,1280);
    });
    await test('foreign product station staff and owner identity claims are rejected',async()=>{
      let scope=sa;
      check(await denied(a,scope,'availability',{id:bproduct,available:0})===403);
      const r=await evaluate(a,async({scope,cat,station})=>{
        const doc=new DOMParser().parseFromString(await(await fetch('/merchant-dev.php?restaurant=1&merchant='+scope.merchant+'&outlet='+scope.outlet)).text(),'text/html');
        const p=new URLSearchParams({_csrf:doc.querySelector('[name=_csrf]').value,action:'restaurant-product',...scope,version:doc.querySelector('[data-restaurant]').dataset.version,request:crypto.randomUUID().replaceAll('-',''),id:'0',name:'Foreign routing',description:'',sku:'FOREIGN',category:String(cat),station:String(station),available:'1','variants[0][id]':'0','variants[0][label]':'Regular','variants[0][unit]':'portion','variants[0][quantity]':'1','variants[0][price]':'20.00'});
        return(await fetch('/merchant-dev.php',{method:'POST',body:p})).status;
      },{scope:sa,cat:cats[0],station:bstation});check(r===403);
      check(await evaluate(b,async scope=>(await fetch('/merchant-dev.php?restaurant=1&merchant='+scope.merchant+'&outlet='+scope.outlet)).status,sa)===403);
    });
    await test('unavailable product is absent from preview and price variants remain exact',async()=>{
      await perform(a,sa,'availability',{id:products[0],available:0});await navigate(a,sa,'preview');
      check(await evaluate(a,id=>!document.querySelector('[data-preview-product="'+id+'"]')&&document.querySelectorAll('[data-preview-product]').length===4,products[0]));
      await perform(a,sa,'availability',{id:products[0],available:1});await navigate(a,sa,'preview');
      check(await evaluate(a,id=>document.querySelector('[data-preview-product="'+id+'"]').innerText.includes('₹20.00')&&document.querySelector('[data-preview-product="'+id+'"]').innerText.includes('₹35.00'),products[0]));
      await screenshot(a,'m02c-configured-preview',1280,800);
    });
    await test('A creates and privately activates cashier and kitchen staff with explicit station grants',async()=>{
      for(const [username,name,role]of [['a-cashier','Synthetic A Cashier','cashier'],['a-kitchen','Synthetic A Kitchen','kitchen']]){
        const user=(await perform(a,sa,'staff-create',{username,name,role})).userId;staff.push(user);
        await perform(a,sa,'staff-activate',{user,password:config.staffPassword,confirmation:config.staffPassword});
        if(role==='kitchen')await perform(a,sa,'staff-access',{user,role,enabled:1,stations:[stations[0],stations[1]]});
      }
      const user=(await perform(b,sb,'staff-create',{username:'b-cashier',name:'Synthetic B Cashier',role:'cashier'})).userId;
      check(await denied(a,sa,'staff-activate',{user,password:config.staffPassword,confirmation:config.staffPassword})===403);
      await perform(b,sb,'staff-activate',{user,password:config.staffPassword,confirmation:config.staffPassword});
      await navigate(a,sa,'staff');await screenshot(a,'m02c-staff-setup',800,1280);
    });
    await test('staff normal authentication and kitchen permissions work while restaurant administration is denied',async()=>{
      const kitchen=await context();await login(kitchen,{username:'a-kitchen',password:config.staffPassword,outlet:fa.outlet});
      const q=await action(kitchen,{action:'queue',...sa,station:String(stations[0])});check(q.status===200&&q.body.data.length===0);
      check(await evaluate(kitchen,async scope=>(await fetch('/merchant-dev.php?restaurant=1&merchant='+scope.merchant+'&outlet='+scope.outlet)).status,sa)===403);
      await perform(a,sa,'staff-access',{user:staff[1],role:'kitchen',enabled:0,stations:[]});
      const deniedQueue=await action(kitchen,{action:'queue',...sa,station:String(stations[0])});check(deniedQueue.status===401);
      await perform(a,sa,'staff-access',{user:staff[1],role:'kitchen',enabled:1,stations:[stations[0]]});
    });
    await test('three responsive views load matching assets and show safe completion with no overflow',async()=>{
      await navigate(a,sa,'preview');
      for(const [w,h]of [[1280,800],[800,1280],[1440,900]]){
        await call('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:false},a);
        const layout=await evaluate(a,()=>({overflow:document.documentElement.scrollWidth>innerWidth,font:parseFloat(getComputedStyle(document.body).fontSize),button:document.querySelector('button').getBoundingClientRect().height}));
        check(!layout.overflow&&layout.font>=16&&layout.button>=44);output.layouts.push({width:w,height:h,...layout});await screenshot(a,'m02c-preview',w,h);
      }
      check(await evaluate(a,()=>['menu','kitchen','staff'].every(key=>document.querySelector('[data-completion="'+key+'"]').innerText.includes('Configured'))&&document.body.innerText.includes('Ordering CLOSED')&&document.body.innerText.includes('UNVERIFIED')));
      for(const asset of ['merchant-setup.css','restaurant-setup.css']){const delivered=await evaluate(a,async asset=>{const r=await fetch('/assets/'+asset);return {status:r.status,text:await r.text()};},asset);check(delivered.status===200&&require('crypto').createHash('sha256').update(delivered.text).digest('hex')===require('crypto').createHash('sha256').update(fs.readFileSync('C:/KiosB0/public/assets/'+asset)).digest('hex'));}
      check(await evaluate(a,async()=>(await fetch('/admin/shop-operations.php')).status)===404);
      check(await evaluate(a,async()=>(await fetch('/merchant-dev.php',{method:'POST',body:new URLSearchParams({action:'restaurant-category',_csrf:'invalid'})})).status)===400);
    });
    await test('logout and fresh owner login retain independent configuration without activating ordering',async()=>{
      await evaluate(a,()=>document.querySelector('header form').requestSubmit());await waitFor(a,()=>!!document.querySelector('[name=username]'));await login(a,fa);await navigate(a,sa,'preview');
      check(await evaluate(a,()=>document.querySelectorAll('[data-preview-product]').length===5&&document.body.innerText.includes('Ordering CLOSED')));
      await navigate(b,sb,'preview');check(await evaluate(b,()=>document.querySelectorAll('[data-preview-product]').length===1&&document.body.innerText.includes('₹55.00')));
      output.restaurants={A:{categories:3,products:5,stations:2,staffRoles:2},B:{categories:1,products:1,stations:1,separateStaff:true},ordering:'CLOSED',legalVerification:'UNVERIFIED'};
    });
    check(output.runtimeExceptions===0);
  }catch(e){output.failed++;output.cases['browser runtime']=e.message;}
  finally{if(socket&&socket.readyState===WebSocket.OPEN){try{await call('Browser.close');}catch{}socket.close();}edge.kill();fs.writeFileSync('C:/Mapped/Results/m02c-browser-result.json',JSON.stringify(output,null,2));process.exit(output.failed?1:0);}
})();
