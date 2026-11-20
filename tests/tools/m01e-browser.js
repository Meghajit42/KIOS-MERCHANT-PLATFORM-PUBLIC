'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M01EBrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9222', '--user-data-dir=C:/M01EBrowserProfile'],
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
(async () => {
  try {
    let version;
    for (let i = 0; i < 60; i++) {
      try { version = await (await fetch('http://127.0.0.1:9222/json/version')).json(); break; } catch {}
      await delay(100);
    }
    if (!version) throw new Error('Guest browser unavailable');
    output.browser = version.Browser;
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      const request = pending.get(message.id);
      if (!request) return;
      clearTimeout(request.timer); pending.delete(message.id);
      if (message.error) request.reject(new Error(message.error.message)); else request.resolve(message.result);
    });
    const owner = await context();
    const kitchen = await context();
    const other = await context();
    const scope = { merchant: config.merchant, outlet: config.outlet };
    await test('separate browser contexts authenticate independent staff privately', async () => {
      await login(owner, config.owner); await login(kitchen, config.kitchen); await login(other, config.other);
      check(await evaluate(owner, () => document.body.innerText.includes('Authorized dashboard')));
      await screenshot(owner, 'authenticated-dashboard', 1280, 800);
      await screenshot(kitchen, 'kitchen-dashboard', 800, 1280);
    });
    await test('browser catalogue and valid foreign context denial', async () => {
      const own = await action(owner, { action: 'catalogue', ...scope, category: 'sweets' });
      check(own.status === 200 && own.body.data.length > 0);
      const denied = await action(owner, { action: 'catalogue', merchant: config.foreignMerchant, outlet: config.foreignOutlet, category: 'sweets' });
      check(denied.status === 403);
    });
    await test('browser CSRF and role boundaries deny unauthorized requests', async () => {
      const status = await evaluate(owner, async () => (await fetch('/merchant-dev.php', {
        method: 'POST', body: new URLSearchParams({ action: 'checkout', _csrf: 'invalid' }) })).status);
      check(status === 400);
      check((await action(kitchen, { action: 'prints', ...scope })).status === 403);
    });
    await test('browser checkout payment preparation KOT collection and print access', async () => {
      const key = require('crypto').randomBytes(32).toString('hex');
      const fields = { action: 'checkout', ...scope, lines: JSON.stringify([config.line]), type: 'takeaway', intent: key };
      const checkout = await action(owner, fields);
      check(checkout.status === 200 && checkout.body.data.status === 'awaiting_payment');
      const repeated = await action(owner, fields);
      check(repeated.body.data.id === checkout.body.data.id);
      // Fixture-only immutable ID lookup. All workflow actions themselves use browser HTTP requests.
      fs.writeFileSync('C:/M01EBrowserOrderPublicId.txt', checkout.body.data.id);
      const lookup = spawn('C:/Mapped/Tools/php/php.exe', ['C:/Mapped/Control/browser-order-id.php'], { stdio: 'ignore', windowsHide: true });
      await new Promise(resolve => lookup.on('exit', resolve));
      const orderId = fs.readFileSync('C:/M01EBrowserOrderId.txt', 'utf8').trim();
      check((await action(owner, { action: 'pay', ...scope, order: orderId })).status === 200);
      const queue = await action(kitchen, { action: 'queue', ...scope, station: String(config.station) });
      check(queue.status === 200);
      const task = queue.body.data.find(row => String(row.orderId) === orderId);
      check(task);
      const kot = await action(kitchen, { action: 'kot', ...scope, task: String(task.taskId) });
      check(kot.status === 200 && kot.body.data.includes('KITCHEN ORDER TICKET'));
      check((await action(kitchen, { action: 'advance', ...scope, task: String(task.taskId), expected: 'queued' })).status === 200);
      check((await action(kitchen, { action: 'advance', ...scope, task: String(task.taskId), expected: 'preparing' })).status === 200);
      check((await action(owner, { action: 'collect', ...scope, order: orderId })).body.data.status === 'collected');
      check((await action(owner, { action: 'prints', ...scope })).status === 200);
      const foreign = await action(other, { action: 'order', merchant: config.foreignMerchant, outlet: config.foreignOutlet, order: orderId });
      check(foreign.status === 200 && foreign.body.data === null);
      output.workflow = { orderId: Number(orderId), taskId: task.taskId, result: 'COLLECTED' };
    });
    await test('browser logout invalidates access', async () => {
      const loggedIn = await evaluate(other, async () => {
        const html = await (await fetch('/merchant-dev.php')).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        await fetch('/merchant-dev.php', { method: 'POST', body: new URLSearchParams({ action: 'logout', _csrf: doc.querySelector('[name=_csrf]').value }) });
        return (await (await fetch('/merchant-dev.php')).text()).includes('Authorized dashboard');
      });
      check(loggedIn === false);
    });
  } catch (error) {
    output.failed++; output.cases['browser runtime'] = error.message;
  } finally {
    if (socket && socket.readyState === WebSocket.OPEN) {
      try { await call('Browser.close'); } catch {}
      socket.close();
    }
    edge.kill();
    fs.writeFileSync('C:/Mapped/Results/m01e-browser-result.json', JSON.stringify(output, null, 2));
    process.exit(output.failed ? 1 : 0);
  }
})();