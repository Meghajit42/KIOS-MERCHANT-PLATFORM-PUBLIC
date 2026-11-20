'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M01FBrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9223', '--user-data-dir=C:/M01FBrowserProfile'],
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
output.responses = [];
async function observed(session, fields) {
  const response = await action(session, fields);
  output.responses.push({ action: fields.action, status: response.status });
  return response;
}
(async () => {
  try {
    let version;
    for (let i = 0; i < 60; i++) {
      try { version = await (await fetch('http://127.0.0.1:9223/json/version')).json(); break; } catch {}
      await delay(100);
    }
    if (!version) throw new Error('Guest browser unavailable');
    output.browser = version.Browser;
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data); const request = pending.get(message.id);
      if (!request) return; clearTimeout(request.timer); pending.delete(message.id);
      if (message.error) request.reject(new Error(message.error.message)); else request.resolve(message.result);
    });
    const owner = await context(); const cashier = await context();
    const kitchen = await context(); const other = await context();
    const scope = { merchant: config.merchant, outlet: config.outlet };
    await test('independent owner cashier kitchen and foreign owner authenticate', async () => {
      await login(owner, config.owner); await login(cashier, config.cashier);
      await login(kitchen, config.kitchen); await login(other, config.other);
      await screenshot(owner, 'm01f-authenticated-owner', 1280, 800);
    });
    await test('cashier reads reconciliation but cannot close or switch merchant', async () => {
      const read = await observed(cashier, { action: 'financial-preview', ...scope });
      check(read.status === 200 && !read.body.data.state.open);
      const fields = { action: 'financial-end-day', ...scope, business_date: read.body.data.summary.businessDate,
        version: String(read.body.data.state.settings.version), request: require('crypto').randomBytes(16).toString('hex'),
        acknowledge: '1', acknowledgement: 'Synthetic browser outstanding plan' };
      check((await observed(cashier, fields)).status === 403);
      check((await observed(owner, { action: 'financial-preview', merchant: config.foreignMerchant, outlet: config.foreignOutlet })).status === 403);
    });
    await test('financial history and immutable closing IDs remain outlet scoped', async () => {
      check((await observed(owner, { action: 'financial-history', ...scope })).status === 200);
      check((await observed(cashier, { action: 'financial-closing', ...scope, closing: String(config.closingId) })).status === 200);
      check((await observed(other, { action: 'financial-closing', merchant: config.foreignMerchant,
        outlet: config.foreignOutlet, closing: String(config.closingId) })).status === 403);
    });
    await test('financial mutations reject CSRF and raw legacy routes remain unavailable', async () => {
      check(await evaluate(owner, async () => (await fetch('/merchant-dev.php', { method: 'POST',
        body: new URLSearchParams({ action: 'financial-end-day', _csrf: 'invalid' }) })).status) === 400);
      check(await evaluate(owner, async () => (await fetch('/admin/shop-operations.php')).status) === 404);
    });
    await test('authorized browser end day is audited and idempotent without opening shop', async () => {
      const preview = (await observed(owner, { action: 'financial-preview', ...scope })).body.data;
      const fields = { action: 'financial-end-day', ...scope, business_date: preview.summary.businessDate,
        version: String(preview.state.settings.version), request: require('crypto').randomBytes(16).toString('hex'),
        acknowledge: '1', acknowledgement: 'Synthetic browser reconciliation plan' };
      const closed = await observed(owner, fields); check(closed.status === 200 && !closed.body.data.duplicate);
      check(closed.body.data.summary.paymentTotalPaise === preview.summary.paymentTotalPaise);
      const retry = await observed(owner, fields);
      check(retry.status === 200 && retry.body.data.duplicate && retry.body.data.closingId === closed.body.data.closingId);
      const state = await observed(owner, { action: 'financial-preview', ...scope }); check(!state.body.data.state.open);
      const history = await observed(owner, { action: 'financial-history', ...scope });
      check(history.body.data.audit.some(row => Number(row.resource_id) === closed.body.data.closingId && row.actor_user_id.endsWith('000000000101')));
      output.closing = { closingId: closed.body.data.closingId, paymentTotalPaise: closed.body.data.summary.paymentTotalPaise };
    });
    await test('actual browser denied financial response is captured', async () => {
      await evaluate(kitchen, fields => {
        const form = document.createElement('form'); form.method = 'POST'; form.action = '/merchant-dev.php';
        fields._csrf = document.querySelector('[name=_csrf]').value;
        for (const [name, value] of Object.entries(fields)) {
          const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
        }
        document.body.append(form); form.submit();
      }, { action: 'financial-preview', ...scope });
      let denied = false;
      for (let i = 0; i < 60; i++) {
        if (await evaluate(kitchen, () => document.body.innerText.includes('Operation denied'))) { denied = true; break; }
        await delay(100);
      }
      check(denied); await screenshot(kitchen, 'm01f-financial-access-denied', 800, 1280);
    });
  } catch (error) {
    output.failed++; output.cases['browser runtime'] = error.message;
  } finally {
    if (socket && socket.readyState === WebSocket.OPEN) {
      try { await call('Browser.close'); } catch {} socket.close();
    }
    edge.kill(); fs.writeFileSync('C:/Mapped/Results/m01f-browser-result.json', JSON.stringify(output, null, 2));
    process.exit(output.failed ? 1 : 0);
  }
})();
