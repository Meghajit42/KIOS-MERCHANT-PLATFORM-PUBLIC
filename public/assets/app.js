const cartSteps = [];
let cartStepRunning = false;
let cartStepBlocked = false;
let cartNeedsRefresh = false;

function liveForms(productId, variantId) {
    return [...document.querySelectorAll('form[data-live-cart]')].filter((form) =>
        form.dataset.productId === productId && form.dataset.variantId === variantId);
}

function showLiveQuantity(form, quantity) {
    form.dataset.liveQuantity = String(quantity);
    const readout = form.querySelector('[data-quantity-readout]');
    if (readout) {
        const unit = quantity === 1 ? readout.dataset.singular : readout.dataset.plural;
        readout.textContent = `${quantity} ${unit || ''}`.trim();
    }
    const add = form.querySelector('[data-live-add]');
    if (add) add.hidden = quantity > 0;
    const minus = form.querySelector('[data-step="-1"]');
    const plus = form.querySelector('[data-step="1"]');
    if (minus) minus.disabled = cartStepBlocked || (!cartStepRunning && cartSteps.length === 0 && quantity === 0);
    if (plus) plus.disabled = cartStepBlocked || (!cartStepRunning && cartSteps.length === 0 && quantity >= 99);
}

function cartPending() {
    return cartStepRunning || cartSteps.length > 0;
}

function updateLiveStatus(productId, variantId, message) {
    liveForms(productId, variantId).forEach((form) => {
        const status = form.querySelector('[data-live-status]');
        if (status) status.textContent = message;
    });
}

function applyCartStep(productId, variantId, result) {
    document.querySelectorAll('form[data-live-cart][data-live-quantities]').forEach((form) => {
        if (form.dataset.productId !== productId) return;
        const quantities = JSON.parse(form.dataset.liveQuantities);
        quantities[variantId] = result.quantity;
        form.dataset.liveQuantities = JSON.stringify(quantities);
    });
    liveForms(productId, variantId).forEach((form) => {
        showLiveQuantity(form, result.quantity);
        const line = form.closest('.cart-line');
        if (line) {
            const choice = line.querySelector('[data-cart-choice]');
            const total = line.querySelector('[data-line-total]');
            if (choice) choice.textContent = result.cartChoice;
            if (total) total.textContent = result.lineTotal;
            if (result.quantity === 0) cartNeedsRefresh = true;
        }
    });
    document.querySelectorAll('[data-cart-count]').forEach((node) => { node.textContent = String(result.cartCount); });
    document.querySelectorAll('[data-cart-total]').forEach((node) => { node.textContent = result.cartTotal; });
    document.querySelectorAll('input[name="checkout_intent_key"]').forEach((input) => { input.value = result.checkoutIntentKey; });
    const cartLink = document.querySelector('.cart-shortcut');
    if (cartLink) cartLink.setAttribute('aria-label', `Open cart, ${result.cartCount} ${result.cartCount === 1 ? 'item' : 'items'}`);
}

async function processCartSteps() {
    if (cartStepRunning || cartStepBlocked) return;
    cartStepRunning = true;
    document.querySelectorAll('form[data-live-cart]').forEach((form) => showLiveQuantity(form, Number.parseInt(form.dataset.liveQuantity, 10) || 0));
    while (cartSteps.length > 0) {
        const step = cartSteps.shift();
        let failureMessage = 'Could not confirm the update. Reload to see your cart.';
        try {
            const response = await fetch(window.location.href, { method: 'POST', body: step.body, credentials: 'same-origin', cache: 'no-store' });
            const result = await response.json();
            if (!response.ok && typeof result.error === 'string') failureMessage = `${result.error} Reload to see your cart.`;
            if (!response.ok || !Number.isInteger(result.quantity) || !Number.isInteger(result.cartCount)
                || typeof result.cartTotal !== 'string' || typeof result.lineTotal !== 'string'
                || typeof result.cartChoice !== 'string' || typeof result.checkoutIntentKey !== 'string') {
                throw new Error('Cart update could not be confirmed.');
            }
            applyCartStep(step.productId, step.variantId, result);
            updateLiveStatus(step.productId, step.variantId, 'Updated');
        } catch {
            cartSteps.length = 0;
            cartStepBlocked = true;
            document.querySelectorAll('[data-live-status]').forEach((status) => { status.textContent = failureMessage; });
            document.querySelectorAll('form[data-live-cart] button, input[name="checkout_intent_key"] + button').forEach((button) => { button.disabled = true; });
            break;
        }
    }
    cartStepRunning = false;
    document.querySelectorAll('form[data-live-cart]').forEach((form) => showLiveQuantity(form, Number.parseInt(form.dataset.liveQuantity, 10) || 0));
    if (!cartStepBlocked && cartNeedsRefresh) window.location.reload();
}

function queueCartStep(form, delta) {
    if (cartStepBlocked) return;
    const body = new FormData(form);
    body.set('action', 'cart-step');
    body.set('product_id', form.dataset.productId);
    body.set('variant_id', form.dataset.variantId);
    body.set('delta', String(delta));
    cartSteps.push({ productId: form.dataset.productId, variantId: form.dataset.variantId, body });
    updateLiveStatus(form.dataset.productId, form.dataset.variantId, 'Updating…');
    processCartSteps();
}

document.querySelectorAll('form[data-live-cart]').forEach((form) => showLiveQuantity(form, Number.parseInt(form.dataset.liveQuantity, 10) || 0));
document.documentElement.classList.add('js-live-cart');

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!input.matches('input[name="variant_id"]')) return;
    const form = input.closest('form[data-live-cart][data-live-quantities]');
    if (!form) return;
    form.dataset.variantId = input.value;
    const quantities = JSON.parse(form.dataset.liveQuantities);
    showLiveQuantity(form, Number.parseInt(quantities[input.value], 10) || 0);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('form[data-live-cart].add-form')) {
        event.preventDefault();
        queueCartStep(form, 1);
    } else if (cartPending() || cartStepBlocked) {
        event.preventDefault();
    }
});

document.addEventListener('click', (event) => {
    if ((cartPending() || cartStepBlocked) && event.target.closest('a')) {
        event.preventDefault();
        return;
    }
    const button = event.target.closest('[data-step]');
    if (!button) return;
    const liveForm = button.closest('form[data-live-cart]');
    if (liveForm) {
        queueCartStep(liveForm, Number.parseInt(button.dataset.step, 10));
        return;
    }
    const input = button.parentElement.querySelector('input[name="quantity"]');
    if (!input) return;
    const current = Number.parseInt(input.value, 10) || 1;
    const step = Number.parseInt(button.dataset.step, 10) || 0;
    const quantity = Math.max(1, Math.min(99, current + step));
    input.value = String(quantity);
    const readout = button.parentElement.querySelector('[data-quantity-readout]');
    if (readout) {
        const unit = quantity === 1 ? readout.dataset.singular : readout.dataset.plural;
        readout.textContent = `${quantity} ${unit || ''}`.trim();
    }
    updateSelectionTotal(button.closest('.product-selection'));
});

// Progressive enhancement: the same server-priced form works inside details without JS.
function updateSelectionTotal(form) {
    if (!form) return;
    const variant = form.querySelector('input[name="variant_id"]:checked, input[type="hidden"][name="variant_id"]');
    const quantity = Number(form.querySelector('input[name="quantity"]').value);
    const price = Number(variant?.dataset.pricePaise);
    if (!Number.isSafeInteger(price) || !Number.isInteger(quantity)) return;
    form.querySelector('.selection-total').hidden = false;
    form.querySelector('[data-selection-total]').textContent = new Intl.NumberFormat('en-IN', {
        style: 'currency', currency: 'INR', minimumFractionDigits: 2,
    }).format(price * quantity / 100);
    form.querySelector('[data-step="-1"]').disabled = quantity <= 1;
    form.querySelector('[data-step="1"]').disabled = quantity >= 99;
}

document.addEventListener('change', (event) => {
    if (event.target.matches('.product-selection input[name="variant_id"]')) {
        updateSelectionTotal(event.target.closest('form'));
    }
});

if (typeof HTMLDialogElement !== 'undefined' && document.querySelector('.product-options')) {
    const dialog = document.createElement('dialog');
    dialog.className = 'product-dialog';
    dialog.setAttribute('aria-labelledby', 'product-dialog-title');
    dialog.innerHTML = '<div class="product-dialog-heading"><h2 id="product-dialog-title"></h2><button type="button" class="button button-outline" data-close-product aria-label="Close product options">Close ×</button></div><div class="product-dialog-photo"></div><div data-product-form></div>';
    document.body.append(dialog);
    let active = null;
    const restore = () => {
        if (!active) return;
        active.details.append(active.form);
        active.details.open = false;
        active.trigger.focus({ preventScroll: true });
        active = null;
    };
    dialog.addEventListener('close', restore);
    dialog.querySelector('[data-close-product]').addEventListener('click', () => dialog.close());
    document.querySelectorAll('.product-options').forEach((details) => {
        const trigger = details.querySelector('summary');
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            if (dialog.open) return;
            const card = details.closest('.product-card');
            const form = details.querySelector('form');
            form.reset();
            const quantity = form.querySelector('input[name="quantity"]');
            quantity.value = '1';
            const readout = form.querySelector('[data-quantity-readout]');
            readout.textContent = `1 ${readout.dataset.singular}`;
            dialog.querySelector('h2').textContent = card.querySelector('h2').textContent;
            const photo = card.querySelector('.product-art').cloneNode(true);
            const image = photo.querySelector('img');
            if (image) { image.sizes = '200px'; image.loading = 'eager'; }
            photo.querySelectorAll('source').forEach((source) => { source.sizes = '200px'; });
            dialog.querySelector('.product-dialog-photo').replaceChildren(photo);
            active = { details, form, trigger };
            dialog.querySelector('[data-product-form]').append(form);
            updateSelectionTotal(form);
            dialog.showModal();
        });
    });
}

const customerHeader = document.querySelector('.topbar');
if (customerHeader && 'ResizeObserver' in window) {
    new ResizeObserver(() => document.documentElement.style.setProperty('--customer-header-height', `${customerHeader.getBoundingClientRect().height}px`)).observe(customerHeader);
}

document.querySelectorAll('[data-scrollable-nav]').forEach((rail) => {
    const frame = rail.closest('[data-category-rail-frame], .staff-nav-frame');
    const controls = frame ? [...frame.querySelectorAll('[data-category-scroll]')] : [];

    const updateRailState = () => {
        const maxScroll = Math.max(0, rail.scrollWidth - rail.clientWidth);
        const hasOverflow = maxScroll > 2;
        const canScrollLeft = hasOverflow && rail.scrollLeft > 2;
        const canScrollRight = hasOverflow && rail.scrollLeft < maxScroll - 2;

        if (frame) {
            frame.dataset.canScrollLeft = String(canScrollLeft);
            frame.dataset.canScrollRight = String(canScrollRight);
        }
        controls.forEach((control) => {
            const direction = Number.parseInt(control.dataset.categoryScroll, 10) || 0;
            control.hidden = !hasOverflow;
            control.disabled = direction < 0 ? !canScrollLeft : !canScrollRight;
        });
    };

    frame?.addEventListener('click', (event) => {
        const control = event.target.closest('[data-category-scroll]');
        if (!control || control.disabled) return;
        const direction = Number.parseInt(control.dataset.categoryScroll, 10) || 0;
        rail.scrollBy({
            left: direction * Math.max(180, Math.round(rail.clientWidth * 0.72)),
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    });

    rail.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        rail.scrollBy({
            left: (event.key === 'ArrowRight' ? 1 : -1) * Math.max(160, Math.round(rail.clientWidth * 0.65)),
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    });
    rail.addEventListener('scroll', updateRailState, { passive: true });

    const selected = rail.querySelector('[aria-current="page"]');
    const revealSelected = () => {
        if (!selected) return;
        const railBounds = rail.getBoundingClientRect();
        const selectedBounds = selected.getBoundingClientRect();
        if (selectedBounds.width > rail.clientWidth) return;
        rail.scrollLeft += (selectedBounds.left + selectedBounds.width / 2)
            - (railBounds.left + rail.clientWidth / 2);
    };
    if (selected) requestAnimationFrame(revealSelected);

    if ('ResizeObserver' in window) new ResizeObserver(() => {
        updateRailState();
        if (selected) requestAnimationFrame(revealSelected);
    }).observe(rail);
    else window.addEventListener('resize', () => {
        updateRailState();
        if (selected) requestAnimationFrame(revealSelected);
    });
    updateRailState();
});

if ('serviceWorker' in navigator && (location.hostname === '127.0.0.1' || location.hostname === 'localhost' || location.protocol === 'https:')) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js').catch(() => {}));
}
