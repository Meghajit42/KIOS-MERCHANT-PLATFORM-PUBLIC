'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const listeners = {};
const readout = { dataset: { singular: 'piece', plural: 'pieces' }, textContent: '' };
const status = { textContent: '' };
const add = { hidden: false, disabled: false };
const counter = { textContent: '' };
const total = { textContent: '' };
const intent = { value: '' };
let form;
function button(step) {
    return { dataset: { step: String(step) }, disabled: false,
        closest: (selector) => selector === '[data-step]' ? this : (selector === 'form[data-live-cart]' ? form : null) };
}
const minus = button(-1);
const plus = button(1);
for (const control of [minus, plus]) {
    control.closest = (selector) => selector === '[data-step]' ? control : (selector === 'form[data-live-cart]' ? form : null);
}
form = {
    dataset: { productId: 'synthetic-piece', variantId: 'one', liveQuantity: '0', liveQuantities: '{"one":0,"two":0}' },
    fields: { _csrf: 'synthetic-csrf', product_id: 'synthetic-piece', variant_id: 'one' },
    matches: (selector) => selector === 'form[data-live-cart].add-form',
    closest: () => null,
    querySelector: (selector) => ({ '[data-quantity-readout]': readout, '[data-live-add]': add,
        '[data-step="-1"]': minus, '[data-step="1"]': plus, '[data-live-status]': status }[selector] || null),
};
const cartLink = { setAttribute() {} };
const document = {
    documentElement: { classList: { add() {} } },
    addEventListener: (name, fn) => { (listeners[name] ||= []).push(fn); },
    querySelectorAll: (selector) => ({ 'form[data-live-cart]': [form], 'form[data-live-cart][data-live-quantities]': [form], '[data-cart-count]': [counter],
        '[data-cart-total]': [total], 'input[name="checkout_intent_key"]': [intent],
        '[data-live-status]': [status], '[data-scrollable-nav]': [],
        'form[data-live-cart] button, input[name="checkout_intent_key"] + button': [minus, plus, add] }[selector] || []),
    querySelector: (selector) => selector === '.cart-shortcut' ? cartLink : null,
};
class FakeFormData {
    constructor(source) { this.values = { ...source.fields }; }
    set(key, value) { this.values[key] = String(value); }
}
const quantities = { one: 0, two: 0 };
let active = 0;
let maximumActive = 0;
let fail = false;
let calls = 0;
const fetch = async (_url, options) => {
    active++;
    maximumActive = Math.max(maximumActive, active);
    calls++;
    await new Promise((resolve) => setTimeout(resolve, 3));
    active--;
    if (fail) throw new Error('synthetic network failure');
    assert.equal(options.body.values._csrf, 'synthetic-csrf');
    assert.equal(options.body.values.action, 'cart-step');
    const variant = options.body.values.variant_id;
    quantities[variant] = Math.max(0, Math.min(99, quantities[variant] + Number(options.body.values.delta)));
    const quantity = quantities[variant];
    const cartCount = quantities.one + quantities.two;
    return { ok: true, json: async () => ({ quantity, cartCount, cartTotal: `₹${cartCount * 20}.00`,
        lineTotal: `₹${quantity * 20}.00`, cartChoice: `${quantity} piece`, checkoutIntentKey: `intent-${cartCount}` }) };
};
const context = { document, FormData: FakeFormData, fetch, navigator: {}, window: { location: { href: 'https://example.test/', reload() {} } },
    setTimeout, console };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/app.js'), 'utf8'), context);

function click(control) {
    for (const listener of listeners.click) listener({ target: control, preventDefault() {} });
}
async function waitFor(predicate) {
    for (let i = 0; i < 300; i++) {
        if (predicate()) return;
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
    throw new Error('Timed out waiting for the synthetic cart queue.');
}

(async () => {
    for (let i = 0; i < 5; i++) click(plus);
    await waitFor(() => calls === 5 && active === 0 && form.dataset.liveQuantity === '5');
    assert.equal(maximumActive, 1, 'requests must be serialized');
    assert.equal(counter.textContent, '5');
    assert.equal(total.textContent, '₹100.00');
    assert.equal(readout.textContent, '5 pieces');
    assert.equal(add.hidden, true);
    for (let i = 0; i < 5; i++) click(minus);
    await waitFor(() => calls === 10 && active === 0 && form.dataset.liveQuantity === '0');
    assert.equal(counter.textContent, '0');
    assert.equal(total.textContent, '₹0.00');
    assert.equal(readout.textContent, '0 pieces');
    assert.equal(add.hidden, false);
    const select = (value) => {
        const input = { value, matches: (selector) => selector === 'input[name="variant_id"]', closest: () => form };
        for (const listener of listeners.change) listener({ target: input });
    };
    select('two');
    click(plus);
    await waitFor(() => calls === 11 && active === 0 && form.dataset.liveQuantity === '1');
    assert.equal(quantities.one, 0);
    assert.equal(quantities.two, 1);
    select('one');
    assert.equal(readout.textContent, '0 pieces');
    select('two');
    assert.equal(readout.textContent, '1 piece');
    click(minus);
    await waitFor(() => calls === 12 && active === 0 && form.dataset.liveQuantity === '0');
    fail = true;
    click(plus);
    await waitFor(() => status.textContent.includes('Could not confirm'));
    assert.equal(plus.disabled, true, 'uncertain failure must require a reload');
    console.log('PASS serialized rapid increments/decrements, authoritative totals, and uncertain failure');
})().catch((error) => { console.error(error); process.exitCode = 1; });
