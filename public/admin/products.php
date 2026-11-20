<?php
declare(strict_types=1);

use Kios\Application\GopalMenuImageCatalog;
use Kios\Domain\Money;
use Kios\Domain\ProductRoutingStatus;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\MenuRepository;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'availability') {
            throw new DomainException('That product action is not available.');
        }
        $idText = adminPost('product_id');
        $versionText = adminPost('version');
        $availableText = adminPost('available');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $idText) || !preg_match('/^[1-9][0-9]{0,8}$/D', $versionText)
            || !in_array($availableText, ['0', '1'], true)) {
            throw new DomainException('Choose a valid menu item and availability setting.');
        }
        $repository->setAvailability(KIOS_ADMIN_OUTLET, (int) $idText, (int) $versionText, $availableText === '1');
        adminFlash($availableText === '1' ? 'Item is available to customers.' : 'Item is hidden from customers.');
    } catch (Throwable $exception) {
        adminFlash(adminFail($exception));
    }
    header('Location: /admin/products.php', true, 303);
    exit;
}
$search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'], 0, 120)) : '';
$categoryFilter = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$availabilityFilter = is_string($_GET['availability'] ?? null) && in_array($_GET['availability'], ['available', 'hidden'], true)
    ? $_GET['availability'] : '';
$filters = ['search' => $search, 'category' => $categoryFilter, 'availability' => $availabilityFilter];
$products = $repository->products(KIOS_ADMIN_OUTLET, $filters);
$categories = $repository->categories(KIOS_ADMIN_OUTLET);
$menu = new MenuRepository(Database::connect());
adminStart('Products');
echo '<a class="admin-button" href="/admin/review.php">Needs Review</a>';
echo '<div class="admin-toolbar"><p>Make quick availability changes, then edit product details when needed.</p><a class="admin-button admin-button-primary" href="/admin/product.php">Add product</a></div>';
echo '<form method="get" class="admin-filter-form"><label>Search products<input type="search" name="search" value="' . adminH($search) . '" placeholder="Type a product name"></label>';
echo '<label>Category<select name="category"><option value="">All categories</option>';
foreach ($categories as $item) {
    echo '<option value="' . adminH($item['slug']) . '"' . ($categoryFilter === $item['slug'] ? ' selected' : '') . '>' . adminH($item['name']) . '</option>';
}
echo '</select></label><label>Availability<select name="availability"><option value="">All items</option><option value="available"' . ($availabilityFilter === 'available' ? ' selected' : '') . '>Available</option><option value="hidden"' . ($availabilityFilter === 'hidden' ? ' selected' : '') . '>Hidden or archived</option></select></label>';
echo '<button class="admin-button admin-button-secondary" type="submit">Filter</button></form>';
if ($products === []) {
    echo '<section class="admin-panel admin-empty"><h2>No products match</h2><p>Clear a filter or add a product to the menu.</p></section>';
} else {
    echo '<section class="admin-product-list" aria-label="Products">';
    foreach ($products as $product) {
        $domainProduct = $menu->findBySku(KIOS_ADMIN_OUTLET, (string) $product['sku']);
        $image = $domainProduct === null
            ? '<img src="/assets/food/snack.svg" alt="" loading="lazy">'
            : GopalMenuImageCatalog::render($domainProduct, true);
        $isActive = (int) $product['active'] === 1;
        $isAvailable = $isActive && (int) $product['available'] === 1;
        $routingStatus = ProductRoutingStatus::tryFrom((string) ($product['routing_status'] ?? ''));
        $routingApproved = $routingStatus === ProductRoutingStatus::Approved;
        $activeVariants = array_values(array_filter($product['variants'], static fn(array $variant): bool => $variant['active']));
        $startingPrice = $activeVariants === [] ? 'No serving options' : Money::fromPaise(min(array_column($activeVariants, 'price_paise')))->formatRupees();
        $variantSummary = implode(' · ', array_map(static fn(array $variant): string => $variant['label'], $activeVariants));
        echo '<article class="admin-product-card"><div class="admin-product-photo">' . $image . '</div><div class="admin-product-main">';
        echo '<div class="admin-product-title"><div><h2>' . adminH($product['name']) . '</h2><p>' . adminH($product['category_name']) . ' · ' . adminH($variantSummary) . '</p></div>';
        echo '<span class="admin-status ' . ($isAvailable ? 'status-on' : 'status-off') . '">' . ($isAvailable ? 'Available' : ($isActive ? 'Hidden' : 'Archived')) . '</span></div>';
        echo '<div class="admin-product-meta"><strong>' . adminH($startingPrice) . '</strong><span>Kitchen station · ' . adminH($product['station_name']) . '</span><span>Routing · ' . adminH($routingStatus?->label() ?? 'invalid route status') . '</span><span>Display order · ' . adminH($product['display_order']) . '</span></div>';
        echo '<div class="admin-product-actions"><a class="admin-button admin-button-secondary" href="/admin/product.php?id=' . adminH($product['product_id']) . '">Edit item</a>';
        if ($isActive && $routingApproved) {
            echo '<form method="post">' . adminCsrfField() . '<input type="hidden" name="action" value="availability"><input type="hidden" name="product_id" value="' . adminH($product['product_id']) . '"><input type="hidden" name="version" value="' . adminH($product['version']) . '"><input type="hidden" name="available" value="' . ($isAvailable ? '0' : '1') . '">';
            echo '<button class="admin-button ' . ($isAvailable ? 'admin-button-hide' : 'admin-button-primary') . '" type="submit" aria-pressed="' . ($isAvailable ? 'true' : 'false') . '">' . ($isAvailable ? 'Hide from customers' : 'Make available') . '</button></form>';
        } elseif ($isActive) {
            echo '<span class="admin-status status-off">Route approval required before availability</span>';
        }
        echo '</div></div></article>';
    }
    echo '</section>';
}
adminEnd();
