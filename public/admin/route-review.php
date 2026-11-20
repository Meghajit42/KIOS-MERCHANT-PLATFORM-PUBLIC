<?php
declare(strict_types=1);

use Kios\Domain\OperationalDepartment;
use Kios\Domain\ProductRoutingStatus;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'save-product-routes') {
            throw new DomainException('That route review action is not available.');
        }
        $selected = $_POST['selected'] ?? [];
        $routeFields = $_POST['routes'] ?? [];
        if (!is_array($selected) || !is_array($routeFields) || count($selected) > 100) {
            throw new DomainException('Select up to one hundred product routes at a time.');
        }
        $routes = [];
        foreach ($selected as $idText) {
            if (!is_string($idText) || !preg_match('/^[1-9][0-9]{0,17}$/D', $idText)) {
                throw new DomainException('A selected product route is invalid. Reload the review page.');
            }
            $fields = $routeFields[$idText] ?? null;
            if (!is_array($fields) || !is_string($fields['version'] ?? null)
                || !preg_match('/^[1-9][0-9]{0,8}$/D', $fields['version'])
                || !is_string($fields['station_code'] ?? null)
                || !is_string($fields['routing_status'] ?? null)) {
                throw new DomainException('A selected route needs a version, station, and review decision.');
            }
            $routes[] = [
                'product_id' => (int) $idText,
                'version' => (int) $fields['version'],
                'station_code' => strtoupper(trim($fields['station_code'])),
                'routing_status' => trim($fields['routing_status']),
            ];
        }
        $saved = $repository->reviewProductRoutes(KIOS_ADMIN_OUTLET, $routes);
        adminFlash($saved . ' selected product route' . ($saved === 1 ? '' : 's') . ' saved and audited.');
    } catch (Throwable $exception) {
        adminFlash(adminFail($exception));
    }
    header('Location: /admin/route-review.php', true, 303);
    exit;
}

$products = $repository->productRoutesForReview(KIOS_ADMIN_OUTLET);
$stations = $repository->stations(KIOS_ADMIN_OUTLET);
adminStart('Product route review');
echo '<p class="admin-intro">Review products whose floor or station is unapproved or incomplete. Nothing is approved automatically. Select only the rows reviewed in this batch; the chosen station must have a valid floor before approval. Saved routes use product version checks and create audit entries.</p>';
echo '<p class="admin-muted">' . count($products) . ' active products need route review. Approval enables routing; availability remains separately controlled.</p>';
if ($products === []) {
    echo '<section class="admin-panel"><h2>No unresolved product routes</h2><p>Every active product has an approved route to a configured floor.</p></section>';
} else {
    echo '<form method="post" class="admin-route-review-form">' . adminCsrfField();
    echo '<input type="hidden" name="action" value="save-product-routes"><div class="admin-category-list">';
    foreach ($products as $product) {
        $status = ProductRoutingStatus::tryFrom((string) $product['routing_status']) ?? ProductRoutingStatus::OwnerReview;
        $currentDepartmentCode = $product['station_department_code'] ?: $product['default_department_code'];
        $currentDepartment = OperationalDepartment::tryFrom((string) $currentDepartmentCode)?->label() ?? 'Unassigned';
        $proposedDepartment = OperationalDepartment::tryFrom((string) ($product['default_department_code'] ?? ''))?->label() ?? 'No category-level proposal';
        $rationale = match (true) {
            $status === ProductRoutingStatus::OwnerReview => 'The product route needs explicit owner confirmation; do not infer a floor from the name.',
            $status === ProductRoutingStatus::Proposed => 'The current route is a category proposal. Confirm the specific station and floor for the pilot.',
            default => 'The product has no valid active station or floor assignment.',
        };
        $id = (int) $product['product_id'];
        echo '<fieldset class="admin-panel admin-route-review-row"><legend><label><input type="checkbox" name="selected[]" value="' . adminH($id) . '"> Review this product</label></legend>';
        echo '<input type="hidden" name="routes[' . adminH($id) . '][version]" value="' . adminH($product['version']) . '">';
        echo '<div><strong>' . adminH($product['name']) . '</strong><small class="admin-muted">' . adminH($product['sku']) . ' · ' . adminH($product['category_name']) . '</small>';
        echo '<p class="admin-muted">Current route: ' . adminH($product['station_name']) . ' · ' . adminH($currentDepartment) . ' · ' . adminH($status->label()) . '</p>';
        echo '<p class="admin-muted">Proposed floor: ' . adminH($proposedDepartment) . ' (category default; confirm per product)</p>';
        echo '<p class="admin-muted">Review reason: ' . adminH($rationale) . '</p></div>';
        echo '<label>Station and floor<select name="routes[' . adminH($id) . '][station_code]" required>';
        foreach ($stations as $station) {
            $department = OperationalDepartment::tryFrom((string) ($station['department_code'] ?? ''))?->label() ?? 'Unassigned floor';
            echo '<option value="' . adminH($station['code']) . '"' . ($station['code'] === $product['station_code'] ? ' selected' : '') . '>'
                . adminH($station['name']) . ' · ' . adminH($department) . '</option>';
        }
        echo '</select></label><label>Decision<select name="routes[' . adminH($id) . '][routing_status]" required>';
        foreach (ProductRoutingStatus::cases() as $statusOption) {
            echo '<option value="' . adminH($statusOption->value) . '"' . ($statusOption === $status ? ' selected' : '') . '>' . adminH($statusOption->label()) . '</option>';
        }
        echo '</select></label></fieldset>';
    }
    echo '</div><button class="admin-button admin-button-primary" type="submit">Save selected route reviews</button></form>';
}
adminEnd();
