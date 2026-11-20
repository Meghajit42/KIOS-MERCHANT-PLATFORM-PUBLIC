<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
$repository = adminRepository();
$products = $repository->products(KIOS_ADMIN_OUTLET);
adminStart('Owner overview');
echo '<section class="admin-card-grid">';
$cards = [
    ['/admin/shop-operations.php', 'Shop operations', 'See shop status, operating hours, outstanding orders and daily closing.', 'Open shop controls'],
    ['/admin/products.php', 'Manage products', 'Edit names, prices, servings, images and kitchen stations.', count($products) . ' products'],
    ['/admin/catalogue-review.php', 'Catalogue review', 'Review product photographs and record merchant decisions.', 'Review catalogue'],
    ['/admin/route-review.php', 'Preparation routes', 'Review where products are prepared before ordering is approved.', 'Review routes'],
    ['/admin/changes.php', 'Recent changes', 'Review recorded menu changes.', 'View history'],
];
if (\Kios\Infrastructure\StaffAccountService::enabled()) {
    $cards[] = ['/admin/staff.php', 'Staff management', 'Manage staff access and roles.', 'Manage staff'];
}
foreach ($cards as [$url, $title, $description, $meta]) {
    echo '<a class="admin-card" href="' . adminH($url) . '"><span class="admin-card-arrow" aria-hidden="true">↗</span>';
    echo '<h2>' . adminH($title) . '</h2><p>' . adminH($description) . '</p><strong>' . adminH($meta) . '</strong></a>';
}
echo '</section><section class="admin-panel admin-home-note"><div><p class="admin-eyebrow">SAFE MENU CHANGES</p><h2>Customer prices use rupees, orders keep their own record</h2>';
echo '<p>New products start hidden. Archiving keeps their past order details intact. Menu edits apply to orders placed after the change.</p></div><a class="admin-button admin-button-primary" href="/admin/products.php">Open product list</a></section>';
adminEnd();
