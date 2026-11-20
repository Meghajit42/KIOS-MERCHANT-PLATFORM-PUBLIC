<?php
declare(strict_types=1);

use Kios\Domain\Money;
use Kios\Domain\QuantityUnit;
use Kios\Infrastructure\MenuImageService;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
$idText = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$productId = null;
if ($idText !== '') {
    if (!preg_match('/^[1-9][0-9]{0,17}$/D', $idText)) {
        http_response_code(404);
        exit('Not found.');
    }
    $productId = (int) $idText;
}
$product = $productId === null ? null : $repository->product(KIOS_ADMIN_OUTLET, $productId);
if ($productId !== null && $product === null) {
    http_response_code(404);
    exit('Not found.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        $action = adminPost('action');
        $postedId = adminPost('product_id');
        $postedVersion = adminPost('version');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $postedId) && !($action === 'save' && $postedId === 'new')) {
            throw new DomainException('Choose a valid menu item.');
        }
        $targetId = $postedId === 'new' ? null : (int) $postedId;
        $version = $targetId === null ? 0 : (preg_match('/^[1-9][0-9]{0,8}$/D', $postedVersion) ? (int) $postedVersion : 0);
        if ($action === 'save') {
            $rawVariants = $_POST['variants'] ?? [];
            if (!is_array($rawVariants) || count($rawVariants) > 12) {
                throw new DomainException('Add no more than twelve serving options.');
            }
            $variants = [];
            foreach ($rawVariants as $key => $row) {
                if (!is_array($row)) {
                    throw new DomainException('A serving option is incomplete.');
                }
                $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
                $priceText = is_string($row['price'] ?? null) ? trim($row['price']) : '';
                if ($label === '' && $priceText === '' && !isset($row['id'])) {
                    continue;
                }
                $variantId = $row['id'] ?? null;
                $variant = [
                    'label' => $label,
                    'unit' => is_string($row['unit'] ?? null) ? $row['unit'] : '',
                    'quantity_value' => is_string($row['quantity_value'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $row['quantity_value']) ? (int) $row['quantity_value'] : 0,
                    'price' => Money::fromRupees($priceText),
                    'active' => !empty($row['active']),
                    'display_order' => is_string($row['display_order'] ?? null) && preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $row['display_order']) ? (int) $row['display_order'] : count($variants),
                ];
                if (is_string($variantId) && preg_match('/^[1-9][0-9]{0,17}$/D', $variantId)) {
                    $variant['id'] = (int) $variantId;
                }
                $variants[] = $variant;
            }
            if ($targetId === null) {
                $productId = $repository->saveProduct(KIOS_ADMIN_OUTLET, null, 0, [
                    'name' => adminPost('name'), 'category' => adminPost('category'), 'station' => adminPost('station'),
                    'routing_status' => adminPost('routing_status'), 'vegetarian' => isset($_POST['vegetarian']), 'available' => false, 'display_order' => adminPost('display_order'),
                ], $variants);
            } else {
                $productId = $repository->saveProduct(KIOS_ADMIN_OUTLET, $targetId, $version, [
                    'name' => adminPost('name'), 'category' => adminPost('category'), 'station' => adminPost('station'),
                    'routing_status' => adminPost('routing_status'), 'vegetarian' => isset($_POST['vegetarian']), 'available' => isset($_POST['available']), 'display_order' => adminPost('display_order'),
                ], $variants);
            }
            adminFlash($targetId === null ? 'Product created hidden from customers.' : 'Product details saved.');
            header('Location: /admin/product.php?id=' . rawurlencode((string) $productId), true, 303);
            exit;
        }
        if ($targetId === null) {
            throw new DomainException('Save the new product before using this action.');
        }
        if ($action === 'archive') {
            $repository->archive(KIOS_ADMIN_OUTLET, $targetId, $version);
            adminFlash('Product archived and hidden from customers.');
        } elseif ($action === 'restore') {
            $repository->restore(KIOS_ADMIN_OUTLET, $targetId, $version);
            adminFlash('Product restored. It remains hidden until you make it available.');
        } elseif ($action === 'upload-image') {
            $file = $_FILES['image'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
                || !is_string($file['name'] ?? null)) {
                throw new DomainException('Choose a JPEG, PNG or WebP image to upload.');
            }
            $current = $repository->product(KIOS_ADMIN_OUTLET, $targetId);
            if ($current === null) {
                throw new DomainException('This menu item could not be found.');
            }
            $stored = (new MenuImageService())->store($file['tmp_name'], $file['name'], (string) $current['sku']);
            $repository->updateImage(KIOS_ADMIN_OUTLET, $targetId, $version, $stored['reference'], $stored['width'], $stored['height']);
            adminFlash('Product image saved and optimized.');
        } else {
            throw new DomainException('That product action is not available.');
        }
        header('Location: /admin/product.php?id=' . rawurlencode((string) $targetId), true, 303);
        exit;
    } catch (Throwable $exception) {
        $error = adminFail($exception);
        if ($productId !== null) {
            $product = $repository->product(KIOS_ADMIN_OUTLET, $productId);
        }
    }
}
$categories = array_values(array_filter($repository->categories(KIOS_ADMIN_OUTLET), static fn(array $category): bool => (int) $category['active'] === 1));
$stations = $repository->stations(KIOS_ADMIN_OUTLET);
$isNew = $product === null;
$menu = new \Kios\Infrastructure\MenuRepository(\Kios\Infrastructure\Database::connect());
$domainProduct = !$isNew ? $menu->findBySku(KIOS_ADMIN_OUTLET, (string) $product['sku']) : null;
$preview = $domainProduct === null ? '<img src="/assets/food/snack.svg" alt="Menu item placeholder">' : \Kios\Application\GopalMenuImageCatalog::render($domainProduct, false, true);
adminStart($isNew ? 'Add product' : 'Edit product');
if ($error !== '') {
    echo '<p class="admin-flash" role="alert">' . adminH($error) . '</p>';
}
if ($isNew) {
    $product = ['product_id' => 'new', 'version' => 0, 'name' => '', 'category_slug' => ($categories[0]['slug'] ?? ''), 'station_code' => ($stations[0]['code'] ?? ''), 'routing_status' => 'owner_review', 'vegetarian' => 0, 'available' => 0, 'display_order' => 0, 'variants' => []];
}
echo '<form method="post" class="admin-panel admin-form product-edit-form">' . adminCsrfField();
echo '<input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="' . adminH($product['product_id']) . '"><input type="hidden" name="version" value="' . adminH($product['version']) . '">';
echo '<div class="admin-form-grid"><label>Product name<input name="name" maxlength="180" required value="' . adminH($product['name']) . '"></label>';
echo '<label>Category<select name="category" required>';
foreach ($categories as $category) {
    echo '<option value="' . adminH($category['slug']) . '"' . ($product['category_slug'] === $category['slug'] ? ' selected' : '') . '>' . adminH($category['name']) . '</option>';
}
echo '</select></label><label>Kitchen station<select name="station" required>';
foreach ($stations as $station) {
    $department = \Kios\Domain\OperationalDepartment::tryFrom((string) ($station['department_code'] ?? ''));
    $stationLabel = (string) $station['name'] . ($department === null ? ' · floor needs review' : ' · ' . $department->label());
    echo '<option value="' . adminH($station['code']) . '"' . ($product['station_code'] === $station['code'] ? ' selected' : '') . '>' . adminH($stationLabel) . '</option>';
}
echo '</select><small>The selected station is this product’s routing override. Configure station floors on Floor routing.</small></label><label>Display order<input type="number" name="display_order" min="0" max="9999" step="1" required value="' . adminH($product['display_order']) . '"><small>Smaller numbers appear first within the category.</small></label></div>';
echo '<label>Preparation route review<select name="routing_status" required><option value="owner_review"' . ($product['routing_status'] === 'owner_review' ? ' selected' : '') . '>Owner review required · unclear identity or route</option><option value="proposed"' . ($product['routing_status'] === 'proposed' ? ' selected' : '') . '>Category proposal · blocked until confirmed</option><option value="approved"' . ($product['routing_status'] === 'approved' ? ' selected' : '') . '>Approved for customer orders</option></select><small>Approve only after confirming this product’s department and station. Approval is audited.</small></label>';
echo '<label class="admin-check"><input type="checkbox" name="vegetarian" value="1"' . ((int) $product['vegetarian'] === 1 ? ' checked' : '') . '> Mark as vegetarian</label>';
if (!$isNew && (int) $product['active'] === 1) {
    echo '<label class="admin-check admin-availability-check"><input type="checkbox" name="available" value="1"' . ((int) $product['available'] === 1 ? ' checked' : '') . '> Available to customers</label>';
} elseif ($isNew) {
    echo '<p class="admin-info">New products start hidden. Save the item first, then turn on availability when it is ready.</p>';
}
echo '<div class="admin-section-heading"><div><h2>Serving and price</h2><p>Use rupees. Prices are saved precisely in paise.</p></div><button class="admin-button admin-button-secondary" type="button" data-add-variant>Add serving</button></div>';
echo '<div class="variant-editor" data-variant-editor>';
$variantRows = $product['variants'];
if ($variantRows === []) {
    $variantRows = [['id' => '', 'label' => '', 'unit' => 'plate', 'quantity_value' => 1, 'price_paise' => 0, 'active' => true, 'display_order' => 0]];
}
foreach ($variantRows as $index => $variant) {
    $key = (string) ($variant['id'] ?? ('new' . $index));
    $price = (int) $variant['price_paise'] > 0 ? preg_replace('/^₹/u', '', Money::fromPaise((int) $variant['price_paise'])->formatRupees()) : '';
    echo '<fieldset class="variant-edit-row"><legend>Serving option</legend>';
    if (is_numeric($variant['id'] ?? null) && (int) $variant['id'] > 0) {
        echo '<input type="hidden" name="variants[' . adminH($key) . '][id]" value="' . adminH($variant['id']) . '">';
    }
    echo '<label>Serving name<input name="variants[' . adminH($key) . '][label]" maxlength="100" required value="' . adminH($variant['label']) . '"></label>';
    echo '<label>Price <span class="rupee-prefix">₹</span><input name="variants[' . adminH($key) . '][price]" inputmode="decimal" maxlength="10" required value="' . adminH($price) . '" placeholder="130.00"></label>';
    echo '<label>Serving type<select name="variants[' . adminH($key) . '][unit]">';
    foreach (QuantityUnit::cases() as $unit) {
        echo '<option value="' . adminH($unit->value) . '"' . ($variant['unit'] === $unit->value ? ' selected' : '') . '>' . adminH(ucfirst($unit->value)) . '</option>';
    }
    echo '</select></label><label>Serving quantity<input type="number" min="1" max="100000" step="1" name="variants[' . adminH($key) . '][quantity_value]" required value="' . adminH($variant['quantity_value']) . '"></label>';
    echo '<label>Serving order<input type="number" min="0" max="9999" step="1" name="variants[' . adminH($key) . '][display_order]" value="' . adminH($variant['display_order'] ?? $index) . '"></label>';
    echo '<label class="admin-check"><input type="checkbox" name="variants[' . adminH($key) . '][active]" value="1"' . (!empty($variant['active']) ? ' checked' : '') . '> Active</label></fieldset>';
}
echo '</div><template id="variant-template"><fieldset class="variant-edit-row"><legend>Serving option</legend><label>Serving name<input data-name="label" maxlength="100" required></label><label>Price <span class="rupee-prefix">₹</span><input data-name="price" inputmode="decimal" maxlength="10" required placeholder="130.00"></label><label>Serving type<select data-name="unit">';
foreach (QuantityUnit::cases() as $unit) {
    echo '<option value="' . adminH($unit->value) . '">' . adminH(ucfirst($unit->value)) . '</option>';
}
echo '</select></label><label>Serving quantity<input data-name="quantity_value" type="number" min="1" max="100000" step="1" required value="1"></label><label>Serving order<input data-name="display_order" type="number" min="0" max="9999" step="1" value="0"></label><label class="admin-check"><input data-name="active" type="checkbox" value="1" checked> Active</label><button class="text-button" type="button" data-remove-variant>Remove this serving</button></fieldset></template>';
echo '<p class="admin-reminder">For vegetarian menu images, use photos without visible onion or garlic.</p>';
echo '<div class="admin-form-actions"><a class="admin-button admin-button-secondary" href="/admin/products.php">Cancel</a><button class="admin-button admin-button-primary" type="submit">Save product</button></div></form>';
if (!$isNew) {
    echo '<section class="admin-panel image-management"><div class="admin-section-heading"><div><h2>Product image</h2><p>Current image preview. JPEG, PNG and WebP up to 8 MB.</p></div></div>';
    echo '<div class="admin-image-preview">' . $preview . '</div><form method="post" enctype="multipart/form-data" class="admin-upload-form">' . adminCsrfField();
    echo '<input type="hidden" name="action" value="upload-image"><input type="hidden" name="product_id" value="' . adminH($product['product_id']) . '"><input type="hidden" name="version" value="' . adminH($product['version']) . '">';
    echo '<label>Choose a new image<input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-image-upload required></label><img class="admin-upload-preview" alt="New image preview" hidden>';
    echo '<button class="admin-button admin-button-primary" type="submit">Upload image</button></form></section>';
    echo '<section class="admin-panel admin-retire-panel"><div><h2>' . ((int) $product['active'] === 1 ? 'Retire this product' : 'Restore this product') . '</h2>';
    echo '<p>' . ((int) $product['active'] === 1 ? 'Archiving hides the product and keeps its order history.' : 'A restored product stays hidden until you make it available.') . '</p></div><form method="post">' . adminCsrfField();
    echo '<input type="hidden" name="action" value="' . ((int) $product['active'] === 1 ? 'archive' : 'restore') . '"><input type="hidden" name="product_id" value="' . adminH($product['product_id']) . '"><input type="hidden" name="version" value="' . adminH($product['version']) . '">';
    echo '<button class="admin-button ' . ((int) $product['active'] === 1 ? 'admin-button-danger' : 'admin-button-primary') . '" type="submit">' . ((int) $product['active'] === 1 ? 'Archive product' : 'Restore product') . '</button></form></section>';
}
adminEnd();
