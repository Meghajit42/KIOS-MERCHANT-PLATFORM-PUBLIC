<?php
declare(strict_types=1);

use Kios\Application\CustomerMenuLabels;
use Kios\Application\CartQuantityStepper;
use Kios\Application\GopalMenuImageCatalog;
use Kios\Domain\Cart;
use Kios\Domain\CheckoutIntent;
use Kios\Domain\OrderStatus;
use Kios\Domain\OrderType;
use Kios\Domain\QuantityUnit;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\OrderRepository;
use Kios\Infrastructure\MenuRepository;
use Kios\Infrastructure\ProductAvailabilityRepository;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI === 'cli-server' && defined('KIOS_K7E_TEST_MODE') && KIOS_K7E_TEST_MODE === true
    && defined('KIOS_K7E_TEST_MESSAGE') && ($_GET['_k7e_test_fault'] ?? null) === 'status'
    && is_string($_GET['token'] ?? null) && preg_match('/^G-[0-9]{3,}$/D', $_GET['token']) === 1) {
    throw new RuntimeException(KIOS_K7E_TEST_MESSAGE);
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; manifest-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function postQuantity(): int
{
    $raw = postString('quantity');
    if (!preg_match('/^(?:[1-9]|[1-9][0-9])$/D', $raw)) {
        throw new InvalidArgumentException('Choose a quantity from 1 to 99.');
    }
    return (int) $raw;
}

/** @param array<string, array<string, int>> $cart */
function checkoutFingerprint(array $cart, ?OrderType $type): string
{
    return CheckoutIntent::fingerprintCart($cart, 'demo-outlet', $type ?? OrderType::Takeaway);
}

/** @param array<string, array<string, int>> $cart */
function rotateCheckoutIntent(array $cart, ?OrderType $type): void
{
    $_SESSION['_checkout_intent_key'] = CheckoutIntent::generateKey();
    $_SESSION['_checkout_intent_fingerprint'] = checkoutFingerprint($cart, $type);
    $_SESSION['_checkout_intent_completed'] = false;
}

/** @param array<string, array<string, int>> $cart */
function ensureCheckoutIntent(array $cart, ?OrderType $type, bool $preserveCheckoutMismatch): void
{
    $key = $_SESSION['_checkout_intent_key'] ?? null;
    $fingerprint = $_SESSION['_checkout_intent_fingerprint'] ?? null;
    if (!is_string($key) || !CheckoutIntent::isValidKey($key)
        || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
        rotateCheckoutIntent($cart, $type);
        return;
    }

    $currentFingerprint = checkoutFingerprint($cart, $type);
    if ($cart !== [] && !hash_equals($fingerprint, $currentFingerprint) && !$preserveCheckoutMismatch) {
        rotateCheckoutIntent($cart, $type);
    }
}

/** @param array<string, array<string, int>> $cart */
function rotateCheckoutIntentAfterCartMutation(array $cart, ?OrderType $type): void
{
    $fingerprint = $_SESSION['_checkout_intent_fingerprint'] ?? null;
    $key = $_SESSION['_checkout_intent_key'] ?? null;
    if (!is_string($key) || !CheckoutIntent::isValidKey($key)
        || !is_string($fingerprint) || !hash_equals($fingerprint, checkoutFingerprint($cart, $type))
        || ($_SESSION['_checkout_intent_completed'] ?? false) === true) {
        rotateCheckoutIntent($cart, $type);
    }
}

function csrfToken(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function requireCsrf(): void
{
    $submitted = postString('_csrf');
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(400);
        throw new RuntimeException('Your session check expired. Reload the page and try again.');
    }
}

function formToken(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
}

function pageStart(string $title, int $cartCount, bool $refresh = false): void
{
    $cartLabel = $cartCount === 1 ? '1 item' : $cartCount . ' items';
    $stylePath = dirname(__DIR__) . '/public/assets/app.css';
    clearstatcache(true, $stylePath);
    $styleVersion = filemtime($stylePath) ?: '1';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="theme-color" content="#BA0107"><meta name="description" content="Synthetic Restaurant Demo menu demonstration. Generated synthetic menu and prices for development only.">';
    if ($refresh) {
        echo '<meta http-equiv="refresh" content="5">';
    }
    echo '<link rel="manifest" href="/manifest.webmanifest"><link rel="icon" href="/assets/brand/gmb-favicon-192.png" type="image/png">';
    echo '<link rel="stylesheet" href="/assets/app.css?v=' . h($styleVersion) . '"><title>' . h($title) . ' · Kios demo</title></head><body>';
    echo '<header class="topbar"><a class="brand" href="?view=welcome" aria-label="Synthetic Restaurant Demo demo home">';
    echo '<img src="/assets/brand-product-placeholder.svg?v=' . h($styleVersion) . '" alt="" width="60" height="52"><span><strong>Synthetic Restaurant Demo</strong><small>Synthetic fixtures · Kios demo</small></span></a>';
    echo '<a class="cart-shortcut" href="?view=cart" aria-label="Open cart, ' . h($cartLabel) . '"><span aria-hidden="true">▣</span><span>Cart</span><b data-cart-count>' . h($cartCount) . '</b></a></header>';
    echo '<div class="demo-ribbon"><a href="/catalogue-review.php"><strong>Full Catalogue Review — all products</strong></a><strong>DEMO / STAGING</strong><span>Owner-supplied menu and prices · Not independently verified · Not merchant production</span></div><main class="page-shell">';
}

function pageEnd(): void
{
    $scriptPath = __DIR__ . '/assets/app.js';
    $scriptVersion = is_file($scriptPath) ? hash_file('sha256', $scriptPath) : false;
    echo '</main><footer class="site-footer"><p>Demo / staging only. No payment is collected and no production order is confirmed here.</p></footer>';
    echo '<script src="/assets/app.js?v=' . h(is_string($scriptVersion) ? substr($scriptVersion, 0, 16) : '1') . '" defer></script></body></html>';
}

function renderClosedShop(array $closedState):never { http_response_code($_SERVER['REQUEST_METHOD']==='POST'?409:200);pageStart('Shop closed',0);echo '<section class="welcome panel"><p class="eyebrow">Synthetic Restaurant Demo</p><h1>We are closed</h1><p class="lead">Thank you for visiting. New orders are currently paused.</p><p>Next scheduled opening: <strong>'.h($closedState['nextOpening']).'</strong></p><p>Hours: '.h($closedState['settings']['opening_time']).'–'.h($closedState['settings']['closing_time']).' IST</p><p>Already paid? Please contact staff; existing orders can still be completed.</p><p><a href="/catalogue-review.php">Browse the catalogue</a></p></section>';pageEnd();exit; }

$requestPdo = Database::connect();
$menuRepository = new MenuRepository($requestPdo);
if(\Kios\Infrastructure\ShopOperations::enabled() && !($_SERVER['REQUEST_METHOD']==='GET' && is_string($_GET['token']??null) && preg_match('/^G-[A-Fa-f0-9]{3,32}$/D',$_GET['token']))) {
 $ops=new \Kios\Infrastructure\ShopOperations($requestPdo);$outletQuery=$requestPdo->query("SELECT outlet_id FROM outlets WHERE outlet_code='demo-outlet' AND active=1");$shopState=$ops->state((int)$outletQuery->fetchColumn());
 if(!$shopState['open']) renderClosedShop($shopState);
}

$preparationNotice = $menuRepository->preparationNotice('demo-outlet');
$cart = new Cart(
    static fn(string $sku) => $menuRepository->findBySku('demo-outlet', $sku),
    is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [],
);
$orderType = OrderType::tryFrom(is_string($_SESSION['order_type'] ?? null) ? $_SESSION['order_type'] : '');
ensureCheckoutIntent(
    $cart->toArray(),
    $orderType,
    $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['action'] ?? null) && $_POST['action'] === 'checkout',
);
$flash = is_string($_SESSION['_flash'] ?? null) ? $_SESSION['_flash'] : '';
unset($_SESSION['_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isLiveCartRequest = postString('action') === 'cart-step';
    try {
        requireCsrf();
        $action = postString('action');
        if ($action === 'select-mode') {
            $type = OrderType::tryFrom(postString('order_type'));
            if ($type === null) {
                throw new InvalidArgumentException('Choose takeaway or dine-in to continue.');
            }
            session_regenerate_id(true);
            $_SESSION['order_type'] = $type->value;
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
            rotateCheckoutIntent($cart->toArray(), $type);
            redirectTo('?category=sweets');
        }

        if ($action === 'cart-add') {
            $productId = postString('product_id');
            $variantId = postString('variant_id');
            if ($productId === '' || $variantId === '') {
                throw new InvalidArgumentException('Choose an available product option.');
            }
            $availableIds = (new ProductAvailabilityRepository($requestPdo))->orderableProductIds('demo-outlet');
            if (!in_array($productId, $availableIds, true)) {
                throw new DomainException('This item is no longer available. Please remove it from your order.');
            }
            $cart->add($productId, $variantId, postQuantity());
            $_SESSION['cart'] = $cart->toArray();
            rotateCheckoutIntentAfterCartMutation($cart->toArray(), $orderType);
            $_SESSION['_flash'] = 'Added ✓ to your order.';
            $returnCategory = postString('return_category');
            redirectTo($menuRepository->category('demo-outlet', $returnCategory) ? '?category=' . rawurlencode($returnCategory) : '?view=cart');
        }

        if ($action === 'cart-update') {
            $cart->update(postString('product_id'), postString('variant_id'), postQuantity());
            $_SESSION['cart'] = $cart->toArray();
            rotateCheckoutIntentAfterCartMutation($cart->toArray(), $orderType);
            $_SESSION['_flash'] = 'Cart quantity updated.';
            redirectTo('?view=cart');
        }

        if ($action === 'cart-step') {
            $productId = postString('product_id');
            $variantId = postString('variant_id');
            $delta = postString('delta');
            $product = $menuRepository->findBySku('demo-outlet', $productId);
            $variant = $product?->variant($variantId);
            if ($product === null || $variant === null || !in_array($delta, ['1', '-1'], true)) {
                throw new InvalidArgumentException('This quantity cannot be changed here.');
            }
            $availableIds = $delta === '1' ? (new ProductAvailabilityRepository($requestPdo))->orderableProductIds('demo-outlet') : [];
            $quantity = (new CartQuantityStepper())->step($cart, $product, $variant, (int) $delta,
                $delta !== '1' || in_array($productId, $availableIds, true));
            $_SESSION['cart'] = $cart->toArray();
            rotateCheckoutIntentAfterCartMutation($cart->toArray(), $orderType);
            $lineTotal = \Kios\Domain\Money::zero()->formatRupees();
            foreach ($cart->lines() as $line) {
                if ($line['product']->id === $productId && $line['variant']->id === $variantId) {
                    $lineTotal = $line['lineTotal']->formatRupees();
                    break;
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'quantity' => $quantity,
                'lineTotal' => $lineTotal,
                'cartChoice' => $quantity > 0 ? CustomerMenuLabels::cartQuantity($product, $variant, $quantity) : '',
                'cartCount' => $cart->itemCount(),
                'cartTotal' => $cart->total()->formatRupees(),
                'checkoutIntentKey' => $_SESSION['_checkout_intent_key'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'cart-remove') {
            $cart->remove(postString('product_id'), postString('variant_id'));
            $_SESSION['cart'] = $cart->toArray();
            rotateCheckoutIntentAfterCartMutation($cart->toArray(), $orderType);
            $_SESSION['_flash'] = 'Item removed from the cart.';
            redirectTo('?view=cart');
        }

        if ($action === 'checkout') {
            if ($orderType === null) {
                throw new InvalidArgumentException('Select takeaway or dine-in before checkout.');
            }
            $checkoutKey = postString('checkout_intent_key');
            $sessionKey = $_SESSION['_checkout_intent_key'] ?? '';
            $intentFingerprint = $_SESSION['_checkout_intent_fingerprint'] ?? '';
            if (!is_string($sessionKey) || !CheckoutIntent::isValidKey($sessionKey)
                || !hash_equals($sessionKey, $checkoutKey) || !is_string($intentFingerprint)
                || preg_match('/^[a-f0-9]{64}$/D', $intentFingerprint) !== 1) {
                throw new DomainException('Your cart changed. Reload it before placing the order.');
            }
            $cartState = is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];
            if ($cartState !== [] && !hash_equals($intentFingerprint, checkoutFingerprint($cartState, $orderType))) {
                rotateCheckoutIntent($cartState, $orderType);
                throw new DomainException('Your cart changed. Reload it before placing the order.');
            }
            $order = (new OrderRepository($requestPdo))->createFromCheckoutIntent(
                $cartState,
                $orderType,
                $checkoutKey,
                session_id(),
                $intentFingerprint,
                'demo-outlet',
            );
            if (PHP_SAPI === 'cli' && defined('KIOS_K7_FAULT_AFTER_CHECKOUT_COMMIT') && KIOS_K7_FAULT_AFTER_CHECKOUT_COMMIT === true) {
                $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
                $testRoot = getenv('KIOS_K7_ROOT');
                if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33082
                    || $configuration->database !== 'kios_k7_test' || !is_string($testRoot)
                    || basename($testRoot) !== 'KiosK7Qualification'
                    || !is_dir($testRoot . DIRECTORY_SEPARATOR . 'mariadb-data')) {
                    throw new RuntimeException('K7 post-commit fault injection is restricted to the isolated local qualification database.');
                }
                exit(86);
            }
            $_SESSION['cart'] = [];
            $_SESSION['_checkout_intent_completed'] = true;
            $_SESSION['_flash'] = '';
            $dateScope = '';
            if (\Kios\Infrastructure\ShopOperations::enabled()) {
                $dateQuery = $requestPdo->prepare('SELECT pickup_business_date FROM orders WHERE public_id = ?');
                $dateQuery->execute([$order->id]);
                $dateScope = '&business_date=' . rawurlencode((string) $dateQuery->fetchColumn());
            }
            redirectTo('?token=' . rawurlencode($order->publicToken()) . '&outlet=' . rawurlencode($order->outletId) . $dateScope);
        }

        throw new InvalidArgumentException('That action is not available.');
    } catch (Throwable $error) {
        $isExpectedInputError = $error instanceof InvalidArgumentException || $error instanceof DomainException;
        $_SESSION['cart'] = $cart->toArray();
        if ($isLiveCartRequest) {
            if (!$isExpectedInputError && http_response_code() !== 400) {
                \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'customer_action');
            }
            http_response_code(http_response_code() === 400 ? 400 : ($isExpectedInputError ? 422 : 500));
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => $isExpectedInputError ? $error->getMessage() : 'Could not update the cart. Reload and try again.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['_flash'] = $isExpectedInputError
            ? $error->getMessage()
            : 'The request could not be completed. Reload the page and try again.';
        if (!$isExpectedInputError) {
            \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'customer_action');
        }
        if (http_response_code() === 400) {
            $flash = $isExpectedInputError
                ? $error->getMessage()
                : 'The request could not be completed. Reload the page and try again.';
        } else {
            redirectTo('?view=cart');
        }
    }
}

$cartCount = $cart->itemCount();
$orderToken = is_string($_GET['token'] ?? null) ? strtoupper(trim($_GET['token'])) : '';
$orderOutletCode = is_string($_GET['outlet'] ?? null) ? trim($_GET['outlet']) : '';
$orderView = null;
$orderLookupFailed = false;
if ($orderToken !== '') {
    try {
        $repository = new OrderRepository($requestPdo);
        $orderView = $orderOutletCode !== ''
            ? $repository->findByPickupToken($orderToken, $orderOutletCode, isset($_GET['business_date']) ? (is_string($_GET['business_date']) ? $_GET['business_date'] : '') : null)
            : null;
    } catch (Throwable $error) {
        \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'order_status_lookup');
        http_response_code(503);
        $orderLookupFailed = true;
        $flash = 'Order status is temporarily unavailable. Please refresh in a moment.';
    }
}
if(\Kios\Infrastructure\ShopOperations::enabled() && $orderView===null && $orderToken==='') { $state=(new \Kios\Infrastructure\ShopOperations($requestPdo))->state((int)$requestPdo->query("SELECT outlet_id FROM outlets WHERE outlet_code='demo-outlet'")->fetchColumn()); if(!$state['open'])renderClosedShop($state); }
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : '';
$categoryId = is_string($_GET['category'] ?? null) ? $_GET['category'] : 'sweets';
$category = null;
$customerCategories = [];
$customerProducts = [];
$customerOrderableSkus = [];
$customerMenuLoadFailed = false;
$isCustomerMenu = $orderView === null && !$orderLookupFailed && $orderToken === ''
    && $view !== 'cart' && $view !== 'welcome' && $orderType !== null;
if ($isCustomerMenu) {
    try {
        $customerCategories = $menuRepository->browsingCategories('demo-outlet');
        foreach ($customerCategories as $menuCategory) {
            $customerProducts[$menuCategory->id] = $menuRepository->browsingProductsForCategory('demo-outlet', $menuCategory->id);
            foreach ($menuRepository->customerProductsForCategory('demo-outlet',$menuCategory->id) as $orderableProduct) $customerOrderableSkus[$orderableProduct->id]=true;
        }
        foreach ($customerCategories as $candidateCategory) { if ($candidateCategory->id === $categoryId) { $category = $candidateCategory; break; } }
        if ($category === null && $customerCategories !== []) {
            $category = $customerCategories[0];
            $categoryId = $category->id;
        }
    } catch (Throwable $error) {
        \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'customer_menu_availability');
        http_response_code(503);
        $customerMenuLoadFailed = true;
        $customerCategories = [];
        $customerProducts = [];
        $category = null;
    }
}

pageStart(
    $orderView !== null ? 'Order token' : ($orderToken !== '' ? 'Order status' : ($view === 'cart' ? 'Your order' : ($view === 'welcome' || $orderType === null ? 'Welcome' : ($category?->name ?? 'Menu')))),
    $cartCount,
    $orderView !== null && in_array($orderView->status, [OrderStatus::AwaitingPayment, OrderStatus::Preparing], true),
);
if ($flash !== '') {
    echo '<p class="flash" role="status">' . h($flash) . '</p>';
}

if ($orderView !== null) {
    echo '<section class="confirmation panel"><p class="eyebrow">Order received for this demo</p><h1>Your pickup token</h1>';
    echo '<div class="token" aria-label="Pickup token">' . h($orderView->publicToken()) . '</div>';
    if ($orderView->status === OrderStatus::Preparing) {
        echo '<ol class="order-progress" aria-label="Order progress"><li class="order-progress-done">PAID</li><li class="order-progress-current">PREPARING</li><li>READY</li></ol>';
        echo '<p class="payment-paid">PAID</p><p class="lead">Your order is being prepared at its stations.</p>';
        echo '<p>Your cashier confirmed the demo payment. This status refreshes while preparation is in progress.</p>';
    } elseif ($orderView->status === OrderStatus::Ready) {
        echo '<ol class="order-progress" aria-label="Order progress"><li class="order-progress-done">PAID</li><li class="order-progress-done">PREPARING</li><li class="order-progress-current">READY</li></ol>';
        echo '<p class="payment-paid">READY</p><p class="lead">All required stations have completed your order.</p>';
    } elseif ($orderView->status->value === 'paid') {
        echo '<p class="payment-paid">PAID</p><p class="lead">Your cashier has confirmed this demo payment.</p>';
        echo '<p>This demo records the cashier confirmation only. It does not collect money or send an order to production.</p>';
    } elseif ($orderView->status->value === 'awaiting_payment') {
        echo '<p class="payment-pending">PAYMENT PENDING</p><p class="lead">Proceed to the cash counter with this token.</p>';
        echo '<p>Please keep this token for payment and pickup.</p>';
        echo '<p>No payment was collected. The order is not marked paid and is not sent to production.</p>';
    } else {
        echo '<p class="payment-pending">' . h(strtoupper(str_replace('_', ' ', $orderView->status->value))) . '</p>';
        echo '<p class="lead">This demo order is no longer awaiting payment.</p>';
    }
    echo '<p class="preparation-time-notice" role="note">Please allow ' . h($preparationNotice) . ' for preparation.</p>';
    echo '<a class="button button-primary" href="?view=welcome">Start another demo order</a></section>';
} elseif ($orderLookupFailed) {
    echo '<section class="empty panel"><p class="eyebrow">Order status</p><h1>Status temporarily unavailable</h1>';
    echo '<p>Refresh this page in a moment to check the saved order state.</p></section>';
} elseif ($orderToken !== '') {
    echo '<section class="empty panel"><p class="eyebrow">Order status</p><h1>We could not find that token</h1>';
    echo '<p>Check the token from your order slip and try again.</p><a class="button button-primary" href="?view=welcome">Back to the demo</a></section>';
} elseif ($view === 'welcome' || $orderType === null) {
    echo '<section class="welcome panel"><div class="welcome-brand"><img src="/assets/brand-product-placeholder.svg" alt="Synthetic Restaurant Demo" width="540" height="244"><p>Synthetic demonstration catalogue</p></div>';
    echo '<p class="eyebrow">Synthetic Restaurant Demo · Demo ordering</p><h1>What would you like today?</h1>';
    echo '<p class="lead">Choose how you would like to enjoy your order.</p><div class="mode-grid">';
    foreach ([OrderType::Takeaway, OrderType::DineIn] as $type) {
        $primary = $type === OrderType::Takeaway;
        echo '<form method="post" class="mode-card' . ($primary ? ' mode-primary' : '') . '">' . formToken();
        echo '<input type="hidden" name="action" value="select-mode"><input type="hidden" name="order_type" value="' . h($type->value) . '">';
        echo '<span class="mode-icon" aria-hidden="true">' . ($primary ? '↗' : '◉') . '</span><span class="mode-name">' . ($primary ? 'TAKEAWAY' : 'DINE-IN') . '</span>';
        echo '<span class="mode-copy">' . ($primary ? 'Pack it up and take it with you.' : 'Enjoy your order here.') . '</span>';
        echo '<button class="button ' . ($primary ? 'button-primary' : 'button-secondary') . '" type="submit">Choose ' . ($primary ? 'takeaway' : 'dine-in') . '</button></form>';
    }
    echo '</div><p class="microcopy">Menu content and prices are owner-supplied for this demo and have not been independently verified. No payment or customer details are requested.</p></section>';
} elseif ($view === 'cart') {
    $lines = $cart->lines();
    echo '<section class="section-heading"><div><p class="eyebrow">' . h(strtoupper($orderType?->value ?? 'ORDER')) . '</p><h1>Review your order</h1></div><a class="text-link" href="?category=' . h($categoryId) . '">← Back to menu</a></section>';
    echo '<p class="preparation-time-notice" role="note">Please allow ' . h($preparationNotice) . ' for preparation.</p>';
    if ($lines === []) {
        echo '<section class="empty panel"><p class="empty-icon" aria-hidden="true">▢</p><h2>Your cart is waiting</h2><p>Add a sweet or dish to begin your demo order.</p><a class="button button-primary" href="?category=sweets">Browse the menu</a></section>';
    } else {
        echo '<section class="cart-layout"><div class="cart-lines">';
        foreach ($lines as $line) {
            $product = $line['product']; $variant = $line['variant'];
            echo '<article class="cart-line panel"><div class="cart-line-art">' . GopalMenuImageCatalog::render($product, true) . '</div>';
            $cartUnits = CustomerMenuLabels::quantityUnit($product, $variant);
            echo '<div class="cart-line-main"><h2>' . h($product->name) . '</h2><p class="cart-choice" data-cart-choice>' . h(CustomerMenuLabels::cartQuantity($product, $variant, $line['quantity'])) . '</p><p>' . h($line['unitPrice']->formatRupees()) . ' each</p>';
            if (str_starts_with($product->id, 'voice-') && $variant->unit === \Kios\Domain\QuantityUnit::Gram) {
                echo '<p class="cart-choice">Price basis: ' . h(CustomerMenuLabels::startingPrice($product)) . '</p>';
            }
            if (!$product->active || !$product->available || !$variant->active) {
                echo '<p class="cart-unavailable" role="alert">This item is no longer available. Remove it to continue.</p>';
            }
            $liveQuantity = $variant->unit !== QuantityUnit::Gram;
            $liveAttributes = $liveQuantity ? ' data-live-cart data-product-id="' . h($product->id) . '" data-variant-id="' . h($variant->id) . '" data-live-quantity="' . h($line['quantity']) . '"' : '';
            echo '<div class="cart-actions"><form method="post" class="quantity-form"' . $liveAttributes . '>' . formToken();
            echo '<input type="hidden" name="action" value="cart-update"><input type="hidden" name="product_id" value="' . h($product->id) . '"><input type="hidden" name="variant_id" value="' . h($variant->id) . '">';
            echo '<div class="stepper" role="group" aria-label="Quantity of ' . h($product->name) . '"><button type="button" data-step="-1" aria-label="Decrease ' . h($product->name) . ' quantity">−</button><output aria-live="polite" data-quantity-readout data-singular="' . h($cartUnits['singular']) . '" data-plural="' . h($cartUnits['plural']) . '">' . h($line['quantity'] . ' ' . ($line['quantity'] === 1 ? $cartUnits['singular'] : $cartUnits['plural'])) . '</output><input name="quantity" type="hidden" value="' . h($line['quantity']) . '"><button type="button" data-step="1" aria-label="Increase ' . h($product->name) . ' quantity">+</button></div>';
            echo '<button class="button button-small button-outline" type="submit"' . ($liveQuantity ? ' data-live-update' : '') . '>Update</button>' . ($liveQuantity ? '<span class="live-cart-status" data-live-status role="status" aria-live="polite"></span>' : '') . '</form>';
            echo '<form method="post">' . formToken() . '<input type="hidden" name="action" value="cart-remove"><input type="hidden" name="product_id" value="' . h($product->id) . '"><input type="hidden" name="variant_id" value="' . h($variant->id) . '"><button class="text-button" type="submit">Remove</button></form></div></div>';
            echo '<strong class="line-total" data-line-total>' . h($line['lineTotal']->formatRupees()) . '</strong></article>';
        }
        echo '</div><aside class="summary panel"><h2>Order summary</h2><div class="summary-row"><span>Items</span><span data-cart-count>' . h($cartCount) . '</span></div>';
        echo '<div class="summary-row summary-total"><span>Demo total</span><strong data-cart-total>' . h($cart->total()->formatRupees()) . '</strong></div>';
        echo '<p class="microcopy">The server calculates this total from the current demo menu. Prices are owner-supplied and have not been independently verified.</p>';
        $hasUnavailableLine = count(array_filter($lines, static fn(array $line): bool => !$line['product']->active || !$line['product']->available || !$line['variant']->active)) > 0;
        echo '<form method="post">' . formToken() . '<input type="hidden" name="action" value="checkout"><input type="hidden" name="checkout_intent_key" value="' . h($_SESSION['_checkout_intent_key'] ?? '') . '"><button class="button button-primary button-wide" type="submit"' . ($hasUnavailableLine ? ' disabled' : '') . '>Place demo order</button></form>';
        echo '<p class="pending-note">Confirmation stops at <strong>AWAITING PAYMENT</strong>.</p></aside></section>';
    }
} else {
    echo '<div class="customer-menu-layout"><div class="customer-menu-main">';
    echo '<section class="section-heading"><div><p class="eyebrow">' . h(strtoupper(str_replace('_', ' ', $orderType?->value ?? ''))) . ' · DEMO MENU</p><h1>' . h($category?->name ?? 'Menu') . '</h1></div>';
    echo '<a class="text-link" href="?view=welcome">Change order type</a></section>';
    echo '<p class="preparation-time-notice" role="note">Please allow ' . h($preparationNotice) . ' for preparation.</p>';
    if ($customerMenuLoadFailed) {
        echo '<section class="empty panel"><h2>Menu temporarily unavailable</h2><p>Refresh in a moment to see current items.</p></section>';
    } elseif ($customerCategories === []) {
        echo '<section class="empty panel"><h2>No items are available right now</h2><p>Please ask a staff member for help.</p></section>';
    } else {
    echo '<div class="category-nav-frame" data-category-rail-frame><button class="category-scroll-button category-scroll-prev" type="button" data-category-scroll="-1" aria-label="Scroll categories left" hidden><span aria-hidden="true">‹</span></button>';
    echo '<nav class="category-nav" aria-label="Menu categories" tabindex="0" data-scrollable-nav>';
    foreach ($customerCategories as $item) {
        $active = $item->id === $category?->id;
        echo '<a class="category-chip' . ($active ? ' active' : '') . '" href="?category=' . rawurlencode($item->id) . '"' . ($active ? ' aria-current="page"' : '') . '>' . h($item->name) . '</a>';
    }
    echo '</nav><button class="category-scroll-button category-scroll-next" type="button" data-category-scroll="1" aria-label="Scroll categories right" hidden><span aria-hidden="true">›</span></button></div>';
    $products = $customerProducts[$categoryId] ?? [];
    if ($products === []) {
        echo '<section class="empty panel"><h2>No items are available right now</h2><p>Please choose another menu category or ask a staff member for help.</p></section>';
    } else {
        echo '<section class="product-grid" aria-label="' . h($category->name) . ' demo products">';
        foreach ($products as $productIndex => $product) {
            $variants = $product->variants();
            echo '<article class="product-card panel" data-product-sku="' . h($product->id) . '"><div class="product-art">'
                . GopalMenuImageCatalog::render($product, false, $productIndex === 0) . '</div>';
            echo '<div class="product-info"><div class="product-title-row"><h2>' . h($product->name) . '</h2>';
            if ($product->vegetarian) {
                echo '<span class="veg-mark" aria-label="Vegetarian">●</span>';
            }
            echo '</div>';
            echo '<p class="product-price">' . h(CustomerMenuLabels::startingPrice($product)) . '</p>';
            if (!isset($customerOrderableSkus[$product->id])) {
                echo '<p class="routing-pending" role="note">Visible for inspection · routing pending · not orderable</p><details><summary class="button button-outline">View serving details</summary>';
                foreach ($variants as $option) echo '<p>' . h(CustomerMenuLabels::option($product,$option)) . ' · ' . h($option->unitPrice->formatRupees()) . '</p>';
                echo '</details></div></article>';continue;
            }
            $variants = array_values(array_filter($variants,static fn($variant):bool=>$variant->active));
            $firstVariant = $variants[0];
            echo '<details class="product-options"><summary class="button button-primary">Choose options<span class="visually-hidden"> for ' . h($product->name) . '</span></summary>';
            echo '<form method="post" class="add-form product-selection">' . formToken() . '<input type="hidden" name="action" value="cart-add"><input type="hidden" name="product_id" value="' . h($product->id) . '"><input type="hidden" name="return_category" value="' . h($categoryId) . '">';
            if (count($variants) === 1) {
                echo '<p class="choice-label">' . h($product->categoryId === 'beverage' ? 'Serving' : 'Serving choice') . '</p>';
                echo '<div class="single-choice"><input type="hidden" name="variant_id" data-price-paise="' . h($firstVariant->unitPrice->paise()) . '" value="' . h($firstVariant->id) . '"><span>' . h(CustomerMenuLabels::option($product, $firstVariant)) . '</span><strong>' . h($firstVariant->unitPrice->formatRupees()) . '</strong></div>';
            } else {
                $variantLegend = $product->categoryId === 'beverage' ? 'Choose size' : ($firstVariant->unit->value === 'gram' ? 'Choose weight' : 'Choose pack');
                echo '<fieldset class="variant-options"><legend>' . h($variantLegend) . '</legend><div class="variant-option-list">';
                foreach ($variants as $variantIndex => $variant) {
                    $checked = $variantIndex === 0 ? ' checked' : '';
                    echo '<label class="variant-option"><input type="radio" name="variant_id" data-price-paise="' . h($variant->unitPrice->paise()) . '" value="' . h($variant->id) . '"' . $checked . '><span>'
                        . h(CustomerMenuLabels::option($product, $variant)) . '</span><strong>' . h($variant->unitPrice->formatRupees()) . '</strong></label>';
                }
                echo '</div></fieldset>';
            }
            $quantityUnits = CustomerMenuLabels::quantityUnit($product, $firstVariant);
            echo '<div class="quantity-control"><span class="choice-label">Quantity to add</span><div class="stepper" role="group" aria-label="Quantity of ' . h($product->name) . '">';
            echo '<button type="button" data-step="-1" aria-label="Decrease ' . h($product->name) . ' quantity">−</button><output aria-live="polite" data-quantity-readout data-singular="' . h($quantityUnits['singular']) . '" data-plural="' . h($quantityUnits['plural']) . '">' . h('1 ' . $quantityUnits['singular']) . '</output><input type="hidden" name="quantity" value="1">';
            echo '<button type="button" data-step="1" aria-label="Increase ' . h($product->name) . ' quantity">+</button></div></div>';
            echo '<p class="selection-total" hidden>Selected total <strong data-selection-total aria-live="polite">' . h($firstVariant->unitPrice->formatRupees()) . '</strong></p><button class="button button-primary add-to-order" type="submit">Add to cart</button><p class="selection-help">Added only when you tap Add to cart.</p></form></details></div></article>';
        }
        echo '</section>';
    }
    echo '<p class="menu-photo-note">Images are illustrative references. Final shop portions and presentation require owner approval.</p></div><aside class="desktop-cart-summary panel" aria-label="Cart summary"><p class="eyebrow">Cart summary</p>';
    echo '<div class="desktop-cart-summary-row"><span>Items in cart</span><strong data-cart-count>' . h($cartCount) . '</strong></div>';
    echo '<div class="desktop-cart-summary-row desktop-cart-summary-total"><span>Demo total</span><strong data-cart-total>' . h($cart->total()->formatRupees()) . '</strong></div>';
    echo '<a class="button button-primary button-wide" href="?view=cart">Review order</a>';
    echo '<p class="microcopy">Owner-supplied prices; not independently verified. No payment is collected.</p></aside></div>';
    echo '<a class="mobile-cart-bar" href="?view=cart"><span>View cart · <span data-cart-count>' . h($cartCount) . '</span> items</span><strong data-cart-total>' . h($cart->total()->formatRupees()) . '</strong></a>';
    }
}

pageEnd();
