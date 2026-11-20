<?php
declare(strict_types=1);

return [
    'customer ordering uses live menu, availability, and preparation settings' => static function (string $root): void {
        if (PHP_VERSION_ID < 80000) {
            throw new RuntimeException('PHP 8.x or newer is required');
        }
        $entry = file_get_contents($root . '/public/index.php');
        $menu = file_get_contents($root . '/app/Infrastructure/MenuRepository.php');
        if (!is_string($entry) || !is_string($menu)
            || !str_contains($entry, '$requestPdo = Database::connect()')
            || !str_contains($entry, 'new MenuRepository($requestPdo)')
            || !str_contains($entry, "browsingCategories('demo-outlet')")
            || !str_contains($entry, "browsingProductsForCategory('demo-outlet'")
            || !str_contains($entry, "preparationNotice('demo-outlet')")
            || !str_contains($entry, 'orderableProductIds(\'demo-outlet\')')
            || !str_contains($menu, 'p.active = 1 AND p.available = 1')
            || !str_contains($menu, "p.routing_status = \\'approved\\'")
            || !str_contains($menu, 'pv.active = 1')
            || !str_contains($menu, 'o.outlet_code = :outlet')) {
            throw new RuntimeException('customer ordering does not use outlet-scoped database menu and availability reads');
        }
    },
];
