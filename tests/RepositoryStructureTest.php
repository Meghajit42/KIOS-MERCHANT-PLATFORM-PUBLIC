<?php
declare(strict_types=1);

return [
    'trusted repository structure exists' => static function (string $root): void {
        $required = [
            'AGENTS.md', 'README.md', '.gitignore',
            'app/Domain', 'app/Application', 'app/Infrastructure', 'app/Support',
            'public/index.php', 'public/assets/app.css', 'public/assets/app.js',
            'public/manifest.webmanifest', 'public/service-worker.js',
            'app/Domain/Money.php', 'app/Application/DemoCatalog.php',
            'app/Domain/OperationalDepartment.php', 'app/Domain/ProductRoutingStatus.php', 'app/Domain/StaffRole.php',
            'app/Application/CollectionQueueView.php',
            'app/Application/GopalMenuV1.php', 'app/Infrastructure/MenuBackupExporter.php',
            'database/migrations/001_ordering_foundation.sql',
            'database/migrations/002_cashier_payments.sql',
            'database/migrations/005_short_pickup_tokens.sql',
            'database/migrations/006_menu_management.sql',
            'database/migrations/007_durable_checkout_idempotency.sql',
            'database/migrations/009_operational_departments.sql',
            'app/Infrastructure/OrderRepository.php', 'public/cashier.php',
            'app/Infrastructure/MenuAdminRepository.php', 'app/Infrastructure/MenuImageService.php',
            'public/admin/login.php', 'public/admin/products.php', 'public/admin/categories.php',
            'public/admin/departments.php',
            'bin/migrate.php', 'bin/seed-demo.php', 'bin/import-gopal-menu-v1.php', '.env.example',
            'app/Infrastructure/DatabaseConfiguration.php', 'public/demo.php',
            'public/.htaccess', 'public/.user.ini', 'bin/hostinger-preflight.php',
            'app/Infrastructure/StaffAccessPolicy.php',
            'deploy/hostinger/hostinger.env.example', 'deploy/hostinger/HOSTINGER_DEPLOYMENT.md',
            'config', 'storage/data', 'storage/logs', 'tests', 'docs',
            'docs/PRODUCT_BRIEF.md', 'docs/ARCHITECTURE.md', 'docs/USER_FLOWS.md',
            'docs/MODEL_ROUTING.md', 'docs/SECURITY_BOUNDARIES.md',
        ];
        foreach ($required as $path) {
            if (!file_exists($root . '/' . $path)) {
                throw new RuntimeException("missing required path: {$path}");
            }
        }
    },
    'K5.12 migration is additive, outlet-day scoped, and ledger guarded' => static function (string $root): void {
        $migration = file_get_contents($root . '/database/migrations/005_short_pickup_tokens.sql');
        $runner = file_get_contents($root . '/app/Infrastructure/MigrationRunner.php');
        if (!is_string($migration) || !is_string($runner)
            || !str_contains($migration, 'ADD COLUMN IF NOT EXISTS pickup_token')
            || !str_contains($migration, 'ADD COLUMN IF NOT EXISTS pickup_business_date')
            || !str_contains($migration, 'uq_orders_outlet_day_pickup (outlet_id, pickup_business_date, pickup_token)')
            || !str_contains($migration, 'CREATE TABLE IF NOT EXISTS pickup_token_counters')
            || !str_contains($runner, 'schema_migrations')
            || !str_contains($runner, 'isset($already[$name])')) {
            throw new RuntimeException('pickup migration is not additive or protected by the migration ledger');
        }
        foreach (['001_ordering_foundation.sql', '002_cashier_payments.sql', '003_preparation_tasks.sql', '004_thermal_printing.sql'] as $baseline) {
            if (!is_file($root . '/database/migrations/' . $baseline)) {
                throw new RuntimeException('an approved baseline migration is missing');
            }
        }
    },
    'K10 routing snapshots assignments and gates staff actions by role' => static function (string $root): void {
        $migration = (string) file_get_contents($root . '/database/migrations/009_operational_departments.sql');
        $orders = (string) file_get_contents($root . '/app/Infrastructure/OrderRepository.php');
        $routing = (string) file_get_contents($root . '/app/Infrastructure/PreparationRoutingService.php');
        $cashier = (string) file_get_contents($root . '/public/cashier.php');
        $admin = (string) file_get_contents($root . '/public/admin/departments.php');
        $routeReview = (string) file_get_contents($root . '/public/admin/route-review.php');
        $adminRepository = (string) file_get_contents($root . '/app/Infrastructure/MenuAdminRepository.php');
        $menu = (string) file_get_contents($root . '/app/Infrastructure/MenuRepository.php');
        $productAdmin = (string) file_get_contents($root . '/public/admin/product.php');
        $collectionView = (string) file_get_contents($root . '/app/Application/CollectionQueueView.php');
        if (!str_contains($migration, 'department_code_snapshot')
            || !str_contains($migration, 'routing_status')
            || !str_contains($migration, 'preparation_task_events')
            || !str_contains($migration, 'changed_by VARCHAR(80)')
            || !str_contains($orders, 'COALESCE(ps.department_code, c.default_department_code)')
            || !str_contains($orders, "routing_status'] ?? null) !== 'approved'")
            || !str_contains($menu, "p.routing_status = \\'approved\\'")
            || !str_contains($productAdmin, 'routing_status')
            || !str_contains($routing, 'A station contains items assigned to different floors')
            || !str_contains($cashier, '$staffRole->allowsAction($action)')
            || !str_contains($cashier, 'markCollectedByPickupToken')
            || !str_contains($cashier, 'readyForCollectionForOutlet')
            || !str_contains($collectionView, 'Mark collected')
            || !str_contains($cashier, 'Payment pending')
            || !str_contains($cashier, 'Payment blocked before confirmation')
            || !str_contains($cashier, 'paymentBlockingReasonsForPublicOrder')
            || !str_contains($orders, 'awaitingPaymentForOutlet')
            || !str_contains($cashier, 'forStation($outletId, $stationCode, $departmentCode)')
            || !str_contains($admin, 'adminRequireLogin()') || !str_contains($admin, 'adminRequireCsrf()')
            || !str_contains($routeReview, 'adminRequireLogin()') || !str_contains($routeReview, 'adminRequireCsrf()')
            || !str_contains($adminRepository, 'product_route_reviewed') || !str_contains($routeReview, 'Review reason:')) {
            throw new RuntimeException('K10 routing no longer fails closed or restricts staff and admin actions');
        }
    },
    'K6 migration stores historical station routing and owner settings additively' => static function (string $root): void {
        $migration = file_get_contents($root . '/database/migrations/006_menu_management.sql');
        if (!is_string($migration) || !str_contains($migration, 'ADD COLUMN station_id_snapshot')
            || !str_contains($migration, 'UPDATE order_items oi') || !str_contains($migration, 'JOIN preparation_tasks pt')
            || !str_contains($migration, 'fk_order_items_station_snapshot')
            || !str_contains($migration, 'CREATE TABLE outlet_menu_settings') || !str_contains($migration, 'CREATE TABLE menu_admin_events')
            || !str_contains($migration, 'ADD COLUMN version') || str_contains($migration, 'DELETE FROM orders')) {
            throw new RuntimeException('K6 migration omits a historical menu safety snapshot or mutates order data');
        }
        foreach (['001_ordering_foundation.sql', '002_cashier_payments.sql', '003_preparation_tasks.sql', '004_thermal_printing.sql', '005_short_pickup_tokens.sql'] as $baseline) {
            if (!is_file($root . '/database/migrations/' . $baseline)) {
                throw new RuntimeException('K6 removed or rewrote an approved earlier migration');
            }
        }
    },
    'K7A checkout intent migration adds a unique scoped durable key' => static function (string $root): void {
        $migration = file_get_contents($root . '/database/migrations/007_durable_checkout_idempotency.sql');
        $preflight = file_get_contents($root . '/bin/hostinger-preflight.php');
        if (!is_string($migration) || !is_string($preflight)
            || !str_contains($migration, 'ADD COLUMN IF NOT EXISTS checkout_idempotency_key_hash BINARY(32) NULL')
            || !str_contains($migration, 'ADD COLUMN IF NOT EXISTS checkout_session_binding_hash BINARY(32) NULL')
            || !str_contains($migration, 'ADD COLUMN IF NOT EXISTS checkout_cart_fingerprint BINARY(32) NULL')
            || !str_contains($migration, 'uq_orders_checkout_idempotency_hash (checkout_idempotency_key_hash)')
            || !str_contains($migration, 'chk_orders_checkout_intent_bundle CHECK')
            || !str_contains($preflight, "glob(\$root . '/database/migrations/[0-9][0-9][0-9]_*.sql')")
            || !str_contains($preflight, 'count($expectedMigrations) >= 10')
            || !str_contains($preflight, 'count($applied) === count($expectedMigrations)')) {
            throw new RuntimeException('K7A migration or deployment preflight omits durable checkout-intent support');
        }
        foreach (['001_ordering_foundation.sql', '002_cashier_payments.sql', '003_preparation_tasks.sql', '004_thermal_printing.sql', '005_short_pickup_tokens.sql', '006_menu_management.sql'] as $baseline) {
            if (!is_file($root . '/database/migrations/' . $baseline)) {
                throw new RuntimeException('K7A removed an approved earlier migration');
            }
        }
    },
];
