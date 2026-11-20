'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const listeners = {};
const stored = new Map();
let online = true;
const self = { location: { origin: 'https://example.test' }, addEventListener: (name, fn) => { listeners[name] = fn; } };
const caches = {
    open: async () => ({ put: async (request, response) => stored.set(request.url, response) }),
    match: async (request) => stored.get(request.url),
};
const fetch = async (request) => {
    if (!online) throw new Error('synthetic offline');
    return { ok: true, version: new URL(request.url).search, clone() { return this; } };
};
const errorResponse = { error: true };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/service-worker.js'), 'utf8'),
    { self, caches, fetch, URL, Response: { error: () => errorResponse } });
async function request(version) {
    let result;
    listeners.fetch({ request: { method: 'GET', url: `https://example.test/assets/app.js?v=${version}` },
        respondWith: (promise) => { result = promise; } });
    return result;
}
(async () => {
    assert.equal((await request('old')).version, '?v=old');
    assert.equal((await request('new')).version, '?v=new');
    online = false;
    assert.equal((await request('old')).version, '?v=old');
    assert.equal((await request('new')).version, '?v=new');
    assert.equal(await request('unknown'), errorResponse, 'an unknown version must not receive stale script');
    console.log('PASS version-specific service-worker cache and offline fallback');
})().catch((error) => { console.error(error); process.exitCode = 1; });
