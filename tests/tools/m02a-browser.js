'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M02ABrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9224', '--user-data-dir=C:/M02ABrowserProfile'],
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
async function waitFor(session, predicate) {
  for (let i = 0; i < 80; i++) { if (await evaluate(session, predicate)) return; await delay(100); }
  throw new Error('Expected internal form state unavailable');
}
async function onboarding(session) {
  await call('Page.navigate', { url: 'http://127.0.0.1:8089/merchant-dev.php?onboarding=1' }, session);
  await waitFor(session, () => document.querySelector('#merchant-provisioning') !== null);
}
(async () => {
  try {
    let version;
    for (let i = 0; i < 60; i++) {
      try { version = await (await fetch('http://127.0.0.1:9224/json/version')).json(); break; } catch {}
      await delay(100);
    }
    if (!version) throw new Error('Guest browser unavailable');
    output.browser = version.Browser; socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data); const request = pending.get(message.id);
      if (!request) return; clearTimeout(request.timer); pending.delete(message.id);
      if (message.error) request.reject(new Error(message.error.message)); else request.resolve(message.result);
    });
    const platform = await context(); const owner = await context(); const oldOwner = await context(); const anonymous = await context();
    let receipt; let createRequest; let activationRequest; let activationVersion;
    await test('platform and owner identities authenticate separately and anonymous onboarding is denied', async () => {
      await login(platform, config.platform); await login(owner, config.newOwner); await login(oldOwner, config.owner);
      check(await evaluate(anonymous, async () => (await fetch('/merchant-dev.php?onboarding=1')).status) === 403);
      check(await evaluate(platform, () => document.body.innerText.includes('000000000100')));
    });
    await test('merchant owners cannot provision activate or inspect administrator confirmations', async () => {
      const fields = { action: 'onboarding-create', request: require('crypto').randomBytes(16).toString('hex'), ...config.input };
      check((await observed(owner, fields)).status === 403);
      check((await observed(oldOwner, { action: 'onboarding-activate', merchant_id: config.newMerchant.merchant_id,
        version: '1', request: require('crypto').randomBytes(16).toString('hex'), password: config.activationPassword,
        confirmation: config.activationPassword })).status === 403);
      check(await evaluate(owner, async id => (await fetch('/merchant-dev.php?onboarding=1&receipt=' + id)).status,
        config.newMerchant.merchant_id) === 403);
    });
    await test('CSRF and client selected identity claims are rejected', async () => {
      check(await evaluate(platform, async fields => (await fetch('/merchant-dev.php', { method: 'POST',
        body: new URLSearchParams({ ...fields, action: 'onboarding-create', request: 'a'.repeat(32), _csrf: 'invalid' }) })).status,
      config.input) === 400);
      check((await observed(platform, { action: 'onboarding-create', request: require('crypto').randomBytes(16).toString('hex'),
        ...config.input, merchant_id: config.newMerchant.merchant_id })).status === 400);
    });
    await test('real internal form presents clear server validation errors', async () => {
      await onboarding(platform);
      await evaluate(platform, fields => {
        for (const [name, value] of Object.entries(fields)) document.querySelector('[name=' + name + ']').value = value;
        document.querySelector('[name=business_name]').value = ''; document.querySelector('#merchant-provisioning').submit();
      }, config.input);
      await waitFor(platform, () => document.querySelector('[role=alert]') !== null);
      check(await evaluate(platform, () => document.querySelector('[role=alert]').innerText.includes('Required details')));
    });
    await test('normal browser form atomically provisions a pending merchant and blocks pending login', async () => {
      await onboarding(platform);
      createRequest = await evaluate(platform, fields => {
        for (const [name, value] of Object.entries(fields)) document.querySelector('[name=' + name + ']').value = value;
        const request = document.querySelector('#merchant-provisioning [name=request]').value;
        document.querySelector('#merchant-provisioning').submit(); return request;
      }, config.input);
      await waitFor(platform, () => document.querySelector('[data-provisioning-confirmation]')?.dataset.status === 'pending_activation');
      receipt = await evaluate(platform, () => {
        const node = document.querySelector('[data-provisioning-confirmation]');
        const values = Object.fromEntries(Array.from(node.querySelectorAll('dt')).map(dt => [dt.textContent, dt.nextElementSibling.textContent]));
        return { merchant: node.dataset.merchant, outlet: values['Outlet ID'], loginOutlet: Number(values['Sign-in outlet number']), username: values['Owner username'] };
      });
      await screenshot(platform, 'm02a-pending-confirmation', 1280, 800);
      const pendingOwner = await context();
      await evaluate(pendingOwner, c => {
        document.querySelector('[name=login_outlet]').value = String(c.loginOutlet);
        document.querySelector('[name=username]').value = c.username;
        document.querySelector('[name=password]').value = c.password; document.querySelector('form').submit();
      }, { ...receipt, password: config.activationPassword });
      await waitFor(pendingOwner, () => document.body.innerText.includes('Sign-in rejected'));
    });
    await test('attended private activation uses the real form and displays confirmation without passwords', async () => {
      const activation = await evaluate(platform, password => {
        const form = document.querySelector('#owner-activation');
        const request = form.querySelector('[name=request]').value; const version = form.querySelector('[name=version]').value;
        form.querySelector('[name=password]').value = password; form.querySelector('[name=confirmation]').value = password;
        form.submit(); return { request, version };
      }, config.activationPassword);
      activationRequest = activation.request; activationVersion = activation.version;
      await waitFor(platform, () => document.querySelector('[data-provisioning-confirmation]')?.dataset.status === 'active');
      check(await evaluate(platform, password => !document.body.innerText.includes(password) && document.body.innerText.includes('Ordering remains CLOSED'), config.activationPassword));
      await screenshot(platform, 'm02a-active-confirmation', 800, 1280);
    });
    await test('newly activated owner signs in normally with only its own tenant authority', async () => {
      const activatedOwner = await context();
      await login(activatedOwner, { username: receipt.username, outlet: receipt.loginOutlet, password: config.activationPassword });
      const own = await observed(activatedOwner, { action: 'catalogue', merchant: receipt.merchant, outlet: receipt.outlet, category: 'sweets' });
      check(own.status === 200 && own.body.data.length === 0);
      check((await observed(activatedOwner, { action: 'catalogue', merchant: config.merchant, outlet: config.outlet, category: 'sweets' })).status === 403);
      check((await observed(activatedOwner, { action: 'financial-preview', merchant: receipt.merchant, outlet: receipt.outlet })).status === 403);
      await call('Page.navigate', { url: 'http://127.0.0.1:8089/merchant-dev.php?onboarding=1' }, activatedOwner);
      await waitFor(activatedOwner, () => document.body.innerText.includes('Operation denied'));
      await screenshot(activatedOwner, 'm02a-owner-admin-denied', 800, 1280);
    });
    await test('browser retries preserve identities and cannot reset the active owner password', async () => {
      const repeated = await observed(platform, { action: 'onboarding-create', request: createRequest, ...config.input });
      check(repeated.status === 200 && repeated.body.data.duplicate && repeated.body.data.receipt.merchant_id === receipt.merchant);
      const fields = { action: 'onboarding-activate', merchant_id: receipt.merchant, version: activationVersion,
        request: activationRequest, password: config.activationPassword, confirmation: config.activationPassword };
      const retry = await observed(platform, fields); check(retry.status === 200 && retry.body.data.duplicate);
      check((await observed(platform, { ...fields, password: 'Different private synthetic password', confirmation: 'Different private synthetic password' })).status === 403);
      output.provisioned = { merchantId: receipt.merchant, outletId: receipt.outlet, status: 'active', publicOrdering: 'CLOSED' };
    });
  } catch (error) { output.failed++; output.cases['browser runtime'] = error.message; }
  finally {
    if (socket && socket.readyState === WebSocket.OPEN) { try { await call('Browser.close'); } catch {} socket.close(); }
    edge.kill(); fs.writeFileSync('C:/Mapped/Results/m02a-browser-result.json', JSON.stringify(output, null, 2)); process.exit(output.failed ? 1 : 0);
  }
})();
