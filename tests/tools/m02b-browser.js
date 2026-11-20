'use strict';
const fs = require('fs');
const { spawn } = require('child_process');
const config = JSON.parse(fs.readFileSync('C:/M02BBrowserCredentials.json', 'utf8'));
const output = { passed: 0, failed: 0, cases: {}, screenshots: [], method: 'Actual isolated Edge, local DevTools protocol' };
const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-debugging-port=9225', '--user-data-dir=C:/M02BBrowserProfile'],
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
async function ownerLogin(session, credential) {
  await evaluate(session, c => {
    document.querySelector('[name=login_outlet]').value = String(c.outlet);
    document.querySelector('[name=username]').value = c.username;
    document.querySelector('[name=password]').value = c.password; document.querySelector('form').submit();
  }, credential);
}
async function progress(session, expected) { await waitFor(session, () => document.querySelector('[data-owner-setup]') !== null);
  for (let i = 0; i < 80; i++) { if (await evaluate(session, () => document.querySelector('[data-owner-setup]')?.dataset.progress) === expected) return; await delay(100); }
  throw new Error('Unexpected persisted progress');
}
async function home(session) { await call('Page.navigate', { url: 'http://127.0.0.1:8089/merchant-dev.php' }, session); }
async function submitForm(session, selector, fields = {}) {
  return evaluate(session, ({ selector, fields }) => {
    const form = document.querySelector(selector);
    for (const [name, value] of Object.entries(fields)) {
      if (Array.isArray(value)) {
        for (const input of form.querySelectorAll('[name="' + name + '[]"]')) input.checked = value.includes(input.value);
      } else form.querySelector('[name="' + name + '"]').value = value;
    }
    const version = form.querySelector('[name=version]')?.value;
    form.requestSubmit(); return version;
  }, { selector, fields });
}
async function formScope(session) { return evaluate(session, () => {
  const state = document.querySelector('[data-owner-setup]');
  return { merchant: state.dataset.merchant, outlet: state.dataset.outlet,
    version: document.querySelector('[name=version]')?.value || '1', request: crypto.randomUUID().replaceAll('-', '') };
}); }
(async () => {
  output.layouts = []; output.runtimeExceptions = 0;
  try {
    let version;
    for (let i = 0; i < 60; i++) { try { version = await (await fetch('http://127.0.0.1:9225/json/version')).json(); break; } catch {} await delay(100); }
    if (!version) throw new Error('Guest browser unavailable'); output.browser = version.Browser;
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data); if (message.method === 'Runtime.exceptionThrown') output.runtimeExceptions++;
      const request = pending.get(message.id); if (!request) return;
      clearTimeout(request.timer); pending.delete(message.id);
      if (message.error) request.reject(new Error(message.error.message)); else request.resolve(message.result);
    });
    const a = await context(); const b = await context(); const admin = await context(); const anonymous = await context();
    for (const session of [a, b, admin, anonymous]) await call('Runtime.enable', {}, session);
    const fa = config.fixtures.browserA; const fb = config.fixtures.browserB;
    await test('owner A first login resolves its own NOT_STARTED setup and anonymous access is denied', async () => {
      await ownerLogin(a, fa); await progress(a, 'NOT_STARTED');
      check(await evaluate(a, id => document.querySelector('[data-owner-setup]').dataset.merchant === id, fa.receipt.merchant_id));
      check(await evaluate(anonymous, async id => (await fetch('/merchant-dev.php?setup=1&merchant=' + id)).status, fa.receipt.merchant_id) === 403);
    });
    await test('owner B must change password before setup and the normal form resumes onboarding', async () => {
      await ownerLogin(b, fb); await waitFor(b, () => document.body.innerText.includes('Change password required'));
      await evaluate(b, c => {
        document.querySelector('[name=current_password]').value = c.current;
        document.querySelector('[name=new_password]').value = c.next;
        document.querySelector('[name=confirm_password]').value = c.next; document.querySelector('form').requestSubmit();
      }, { current: fb.password, next: config.newBrowserBPassword });
      await progress(b, 'NOT_STARTED');
      check(await evaluate(b, id => document.querySelector('[data-owner-setup]').dataset.merchant === id, fb.receipt.merchant_id));
      fb.password = config.newBrowserBPassword;
    });
    await test('actual stylesheet and three responsive layouts are readable without overflow', async () => {
      const css = await evaluate(a, async () => { const r = await fetch('/assets/merchant-setup.css'); return { status: r.status, text: await r.text() }; });
      check(css.status === 200 && require('crypto').createHash('sha256').update(css.text).digest('hex') ===
        require('crypto').createHash('sha256').update(fs.readFileSync('C:/KiosB0/public/assets/merchant-setup.css')).digest('hex'));
      output.stylesheet = { status: css.status, hashMatch: true };
      for (const [width, height] of [[1280, 800], [800, 1280], [1440, 900]]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false }, a);
        const layout = await evaluate(a, () => ({ overflow: document.documentElement.scrollWidth > innerWidth,
          columns: getComputedStyle(document.querySelector('.setup-grid')).gridTemplateColumns.split(' ').length,
          minButton: Math.min(...Array.from(document.querySelectorAll('button')).map(node => node.getBoundingClientRect().height)),
          font: parseFloat(getComputedStyle(document.body).fontSize) }));
        check(!layout.overflow && layout.minButton >= 44 && layout.font >= 16 && layout.columns === (width <= 900 ? 1 : 2));
        output.layouts.push({ width, height, ...layout }); await screenshot(a, 'm02b-setup-grid', width, height);
      }
    });
    await test('CSRF forged approval fields and cross-merchant or outlet changes are rejected', async () => {
      check(await evaluate(a, async () => (await fetch('/merchant-dev.php', { method: 'POST', body: new URLSearchParams({ action: 'setup-submit', _csrf: 'invalid' }) })).status) === 400);
      const scope = await formScope(a);
      check((await observed(a, { action: 'setup-save-business', ...scope, ...config.businessA, progress: 'APPROVED' })).status === 400);
      check((await observed(a, { action: 'setup-review', ...scope, decision: 'APPROVED', note: '' })).status === 403);
      check((await observed(b, { action: 'setup-save-business', ...scope, ...config.businessA })).status === 403);
      const own = await formScope(b);
      const out = { ...config.outletB, 'days[]': config.outletB.days[0] }; delete out.days;
      check((await observed(b, { action: 'setup-save-outlet', ...own, outlet: fa.receipt.merchant_outlet_id, ...out })).status === 403);
    });
    await test('business form validates on the server then saves owner A draft', async () => {
      await evaluate(a, fields => {
        const form = document.querySelector('#business-form');
        for (const [name, value] of Object.entries(fields)) form.querySelector('[name="' + name + '"]').value = value;
        form.querySelector('[name=location]').value = ''; form.submit();
      }, config.businessA);
      await waitFor(a, () => document.querySelector('[role=alert]') !== null);
      await submitForm(a, '#business-form', config.businessA); await progress(a, 'IN_PROGRESS');
      check(await evaluate(a, () => document.querySelector('[name=business_name]').value === 'Synthetic Alpha Cafe'));
    });
    await test('owner A outlet days hours and open preference save without opening ordering', async () => {
      await submitForm(a, '#outlet-form', config.outletA); await progress(a, 'SETUP_COMPLETE');
      check(await evaluate(a, () => document.querySelector('.badge').innerText === 'Ordering closed'));
      await screenshot(a, 'm02b-saved-drafts', 1280, 800);
    });
    await test('merchant B maintains an independent profile and rejects merchant A URL access', async () => {
      await submitForm(b, '#business-form', config.businessB); await progress(b, 'IN_PROGRESS');
      await submitForm(b, '#outlet-form', config.outletB); await progress(b, 'SETUP_COMPLETE');
      check(await evaluate(b, () => document.querySelector('[name=business_name]').value === 'Synthetic Beta Cafe'));
      await call('Page.navigate', { url: 'http://127.0.0.1:8089/merchant-dev.php?setup=1&merchant=' + fa.receipt.merchant_id }, b);
      await waitFor(b, () => document.body.innerText.includes('Operation denied'));
      await screenshot(b, 'm02b-cross-merchant-denied', 800, 1280);
      await home(b); await progress(b, 'SETUP_COMPLETE');
      check(await evaluate(b, () => document.querySelector('[name=business_name]').value === 'Synthetic Beta Cafe'));
      await home(a); await progress(a, 'SETUP_COMPLETE');
      check(await evaluate(a, () => document.querySelector('[name=business_name]').value === 'Synthetic Alpha Cafe'));
    });
    await test('owner submission becomes pending review and cannot be forged into approval', async () => {
      await submitForm(a, '#submit-form'); await progress(a, 'APPROVAL_PENDING');
      await call('Page.navigate', { url: 'http://127.0.0.1:8089/merchant-dev.php?setup=1&merchant=' + fa.receipt.merchant_id + '&progress=APPROVED' }, a);
      await progress(a, 'APPROVAL_PENDING');
      check(await evaluate(a, () => !document.querySelector('#business-form') && document.body.innerText.includes('UNVERIFIED')));
      await screenshot(a, 'm02b-approval-pending', 800, 1280);
    });
    await test('authenticated creator platform rejects then approves corrected setup without enabling commerce', async () => {
      await login(admin, config.platform);
      const reviewUrl = 'http://127.0.0.1:8089/merchant-dev.php?setup-review=1&merchant=' + fa.receipt.merchant_id;
      await call('Page.navigate', { url: reviewUrl }, admin); await waitFor(admin, () => document.querySelector('#review-form') !== null);
      await submitForm(admin, '#review-form', { decision: 'REJECTED', note: 'Clarify the synthetic operating location' }); await progress(admin, 'REJECTED');
      await home(a); await progress(a, 'REJECTED'); await submitForm(a, '#reopen-form'); await progress(a, 'IN_PROGRESS');
      await submitForm(a, '#business-form', config.businessA); await progress(a, 'SETUP_COMPLETE');
      await submitForm(a, '#submit-form'); await progress(a, 'APPROVAL_PENDING');
      await call('Page.navigate', { url: reviewUrl }, admin); await waitFor(admin, () => document.querySelector('#review-form') !== null);
      await submitForm(admin, '#review-form', { decision: 'APPROVED', note: 'Synthetic setup information accepted' }); await progress(admin, 'APPROVED');
      await home(a); await progress(a, 'APPROVED');
      check(await evaluate(a, () => document.querySelector('.badge').innerText === 'Ordering closed' && document.body.innerText.includes('UNVERIFIED')));
      await screenshot(a, 'm02b-approved-setup', 1440, 900);
      output.merchantA = { progress: 'APPROVED', ordering: 'CLOSED', legalVerification: 'UNVERIFIED' };
    });
    await test('logout removes access and normal reauthentication retains persisted progress', async () => {
      await evaluate(a, () => document.querySelector('header form').requestSubmit());
      await waitFor(a, () => document.querySelector('[name=username]') !== null);
      check(await evaluate(a, async id => (await fetch('/merchant-dev.php?setup=1&merchant=' + id)).status, fa.receipt.merchant_id) === 403);
      await ownerLogin(a, fa); await progress(a, 'APPROVED');
      await home(b); await progress(b, 'SETUP_COMPLETE'); output.merchantB = { progress: 'SETUP_COMPLETE', independentProfile: true };
    });
    check(output.runtimeExceptions === 0);
  } catch (error) { output.failed++; output.cases['browser runtime'] = error.message; }
  finally {
    if (socket && socket.readyState === WebSocket.OPEN) { try { await call('Browser.close'); } catch {} socket.close(); }
    edge.kill(); fs.writeFileSync('C:/Mapped/Results/m02b-browser-result.json', JSON.stringify(output, null, 2)); process.exit(output.failed ? 1 : 0);
  }
})();
