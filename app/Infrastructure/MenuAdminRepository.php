<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use Kios\Domain\Money;
use Kios\Domain\OperationalDepartment;
use Kios\Domain\PreparationStation;
use Kios\Domain\ProductRoutingStatus;
use Kios\Domain\QuantityUnit;
use PDO;
use RuntimeException;
use Throwable;

/** Outlet-scoped, transactional owner menu changes with small human-readable audit summaries. */
final class MenuAdminRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $auditActor = 'menu-admin')
    {
    }

    /** @return list<array<string, mixed>> */
    public function categories(string $outletCode): array
    {
        $query = $this->pdo->prepare(
            'SELECT c.category_id, c.slug, c.name, c.default_department_code, c.active, c.sort_order, c.version, '
            . '(SELECT COUNT(*) FROM products p WHERE p.outlet_id = c.outlet_id AND p.category_id = c.category_id AND p.active = 1) AS product_count '
            . 'FROM categories c JOIN outlets o ON o.outlet_id = c.outlet_id '
            . 'WHERE o.outlet_code = :outlet ORDER BY c.sort_order, c.name, c.category_id'
        );
        $query->execute(['outlet' => $outletCode]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{code: string, name: string, department_code: ?string, department_version: int}> */
    public function stations(string $outletCode): array
    {
        $query = $this->pdo->prepare(
            'SELECT ps.code, ps.name, ps.department_code, ps.department_version FROM preparation_stations ps JOIN outlets o ON o.outlet_id = ps.outlet_id '
            . 'WHERE o.outlet_code = :outlet AND o.active = 1 AND ps.active = 1 ORDER BY ps.name'
        );
        $query->execute(['outlet' => $outletCode]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{search?: string, category?: string, availability?: string} $filters
     *  @return list<array<string, mixed>> */
    public function products(string $outletCode, array $filters = []): array
    {
        $sql = 'SELECT p.product_id, p.sku, p.name, p.description, p.category_id, c.slug AS category_slug, c.name AS category_name, '
            . 'p.station_id, ps.code AS station_code, ps.name AS station_name, ps.department_code AS station_department_code, ps.active AS station_active, '
            . 'c.default_department_code, p.routing_status, p.vegetarian, p.active, p.available, p.image_ref, '
            . 'p.display_order, p.version, p.image_width, p.image_height, pv.variant_id, pv.code AS variant_code, pv.label, '
            . 'pv.unit, pv.quantity_value, pv.price_paise, pv.active AS variant_active, pv.display_order AS variant_order, pv.version AS variant_version '
            . 'FROM products p JOIN outlets o ON o.outlet_id = p.outlet_id '
            . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
            . 'LEFT JOIN product_variants pv ON pv.product_id = p.product_id '
            . 'WHERE o.outlet_code = :outlet';
        $params = ['outlet' => $outletCode];
        if (($filters['search'] ?? '') !== '') {
            $sql .= ' AND LOWER(p.name) LIKE :search';
            $params['search'] = '%' . strtolower(substr((string) $filters['search'], 0, 120)) . '%';
        }
        if (($filters['category'] ?? '') !== '') {
            $sql .= ' AND c.slug = :category';
            $params['category'] = $filters['category'];
        }
        if (($filters['availability'] ?? '') === 'available') {
            $sql .= ' AND p.active = 1 AND p.available = 1';
        } elseif (($filters['availability'] ?? '') === 'hidden') {
            $sql .= ' AND (p.active = 0 OR p.available = 0)';
        }
        $sql .= ' ORDER BY c.sort_order, p.display_order, p.name, p.product_id, pv.display_order, pv.variant_id';
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $grouped = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['product_id'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = $row;
                $grouped[$id]['variants'] = [];
            }
            if ($row['variant_id'] !== null) {
                $grouped[$id]['variants'][] = [
                    'id' => (int) $row['variant_id'], 'code' => (string) $row['variant_code'],
                    'label' => (string) $row['label'], 'unit' => (string) $row['unit'],
                    'quantity_value' => (int) $row['quantity_value'], 'price_paise' => (int) $row['price_paise'],
                    'active' => (int) $row['variant_active'] === 1, 'display_order' => (int) $row['variant_order'],
                    'version' => (int) $row['variant_version'],
                ];
            }
        }
        return array_values($grouped);
    }

    /** @return array<string, mixed>|null */
    public function product(string $outletCode, int $productId): ?array
    {
        foreach ($this->products($outletCode) as $product) {
            if ((int) $product['product_id'] === $productId) {
                return $product;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> Products whose route still needs explicit owner attention. */
    public function productRoutesForReview(string $outletCode): array
    {
        return array_values(array_filter($this->products($outletCode), static function (array $product): bool {
            $department = $product['station_department_code'] ?: $product['default_department_code'];
            return (int) $product['active'] === 1
                && ($product['routing_status'] !== ProductRoutingStatus::Approved->value
                    || OperationalDepartment::tryFrom((string) $department) === null
                    || (int) $product['station_active'] !== 1);
        }));
    }

    /**
     * Save an explicitly selected batch atomically. Every product is version checked and audited.
     * @param list<array{product_id:int,version:int,station_code:string,routing_status:string}> $routes
     */
    public function reviewProductRoutes(string $outletCode, array $routes): int
    {
        if ($routes === [] || count($routes) > 100) {
            throw new DomainException('Select between one and one hundred product routes to save.');
        }
        $seen = [];
        foreach ($routes as $route) {
            if (!is_array($route) || !is_int($route['product_id'] ?? null) || $route['product_id'] < 1
                || !is_int($route['version'] ?? null) || $route['version'] < 1
                || PreparationStation::tryFrom((string) ($route['station_code'] ?? '')) === null
                || ProductRoutingStatus::tryFrom((string) ($route['routing_status'] ?? '')) === null
                || isset($seen[$route['product_id']])) {
                throw new DomainException('A selected route is incomplete or duplicated. Reload the review page.');
            }
            $seen[$route['product_id']] = true;
        }

        $this->transaction(function () use ($outletCode, $routes): void {
            $outletId = $this->outletId($outletCode);
            $currentQuery = $this->pdo->prepare(
                'SELECT p.version, p.name, p.routing_status, p.available, c.name AS category_name, c.default_department_code, '
                . 'ps.code AS station_code, ps.name AS station_name, ps.department_code AS station_department_code '
                . 'FROM products p JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
                . 'JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
                . 'WHERE p.outlet_id = :outlet AND p.product_id = :product AND p.active = 1' . $this->forUpdate()
            );
            $stationQuery = $this->pdo->prepare(
                'SELECT station_id, name, department_code FROM preparation_stations '
                . 'WHERE outlet_id = :outlet AND code = :code AND active = 1' . $this->forUpdate()
            );
            $update = $this->pdo->prepare(
                'UPDATE products SET station_id = :station_id, routing_status = :routing_status, '
                . 'available = :available, version = version + 1 '
                . 'WHERE outlet_id = :outlet AND product_id = :product AND version = :version'
            );
            foreach ($routes as $route) {
                $currentQuery->execute(['outlet' => $outletId, 'product' => $route['product_id']]);
                $current = $currentQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($current) || (int) $current['version'] !== $route['version']) {
                    throw new DomainException('A selected product changed in another tab. Reload the route review before saving.');
                }
                $stationQuery->execute(['outlet' => $outletId, 'code' => $route['station_code']]);
                $station = $stationQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($station)) {
                    throw new DomainException('Choose an active station in this outlet.');
                }
                $status = ProductRoutingStatus::from($route['routing_status']);
                $departmentCode = $station['department_code'] ?? $current['default_department_code'];
                if ($status === ProductRoutingStatus::Approved
                    && OperationalDepartment::tryFrom(is_string($departmentCode) ? $departmentCode : '') === null) {
                    throw new DomainException('Assign this station or its category to a valid floor before approving the product route.');
                }
                $update->execute([
                    'station_id' => (int) $station['station_id'], 'routing_status' => $status->value,
                    'available' => $status === ProductRoutingStatus::Approved ? (int) $current['available'] : 0, 'outlet' => $outletId,
                    'product' => $route['product_id'], 'version' => $route['version'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('A selected product changed before its route could be saved.');
                }
                $before = $current['name'] . ' · ' . $current['category_name'] . ' · ' . $current['station_name']
                    . ' · ' . $current['routing_status'] . ' · ' . ($current['station_department_code'] ?? $current['default_department_code'] ?? 'unassigned');
                $after = $current['name'] . ' · ' . $current['category_name'] . ' · ' . $station['name']
                    . ' · ' . $status->value . ' · ' . ($departmentCode ?? 'unassigned');
                $this->audit($outletId, 'product_route_reviewed', 'product_route', (string) $route['product_id'], $before, $after);
            }
        });
        return count($routes);
    }

    /** @param list<array{id?: int, label: string, unit: string, quantity_value: int, price: Money, active: bool, display_order: int}> $variants */
    public function saveProduct(string $outletCode, ?int $productId, int $expectedVersion, array $data, array $variants): int
    {
        $name = $this->plainText((string) ($data['name'] ?? ''), 180, 'Product name');
        $categorySlug = $this->slug((string) ($data['category'] ?? ''));
        $stationCode = (string) ($data['station'] ?? '');
        if (PreparationStation::tryFrom($stationCode) === null) {
            throw new DomainException('Choose a valid kitchen station.');
        }
        $routingStatus = null;
        if (array_key_exists('routing_status', $data)) {
            $routingStatus = ProductRoutingStatus::tryFrom((string) $data['routing_status']);
            if ($routingStatus === null) {
                throw new DomainException('Choose whether this product route is approved or needs owner review.');
            }
        }
        $displayOrder = $this->boundedInt($data['display_order'] ?? null, 0, 9999, 'Display order');
        $vegetarian = !empty($data['vegetarian']);
        if ($variants === [] || count($variants) > 12) {
            throw new DomainException('Add between one and twelve serving options.');
        }
        $activeLabels = [];
        $activeVariantCount = 0;
        foreach ($variants as $variant) {
            if (!isset($variant['price']) || !(($variant['price'] ?? null) instanceof Money)
                || QuantityUnit::tryFrom((string) ($variant['unit'] ?? '')) === null) {
                throw new DomainException('A serving option is incomplete.');
            }
            $label = $this->plainText((string) ($variant['label'] ?? ''), 100, 'Serving name');
            $variant['label'] = $label;
            if (!empty($variant['active'])) {
                $activeVariantCount++;
                $normalized = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
                if (isset($activeLabels[$normalized])) {
                    throw new DomainException('Serving names must be unique while active.');
                }
                $activeLabels[$normalized] = true;
            }
        }
        if ($activeVariantCount < 1) {
            throw new DomainException('Keep at least one serving option active.');
        }
        foreach ($variants as &$variant) {
            $variant['display_order'] = $this->boundedInt($variant['display_order'] ?? 0, 0, 9999, 'Serving order');
        }
        unset($variant);
        usort($variants, static fn(array $left, array $right): int => $left['display_order'] <=> $right['display_order']);
        $availableRequested = $productId !== null && !empty($data['available']);
        $createdProductId = null;
        $this->transaction(function () use (&$createdProductId, $outletCode, $productId, $expectedVersion, $name, $categorySlug, $stationCode, $displayOrder, $vegetarian, $availableRequested, $routingStatus, $variants): void {
            $outletId = $this->outletId($outletCode);
            $categoryQuery = $this->pdo->prepare('SELECT category_id, name FROM categories WHERE outlet_id = :outlet AND slug = :slug AND active = 1');
            $categoryQuery->execute(['outlet' => $outletId, 'slug' => $categorySlug]);
            $categoryRow = $categoryQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($categoryRow)) {
                throw new DomainException('Choose an active menu category.');
            }
            $categoryId = (int) $categoryRow['category_id'];
            $categoryName = (string) $categoryRow['name'];
            $stationQuery = $this->pdo->prepare('SELECT station_id, name FROM preparation_stations WHERE outlet_id = :outlet AND code = :code AND active = 1');
            $stationQuery->execute(['outlet' => $outletId, 'code' => $stationCode]);
            $stationRow = $stationQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stationRow)) {
                throw new DomainException('Choose an active kitchen station.');
            }
            $stationId = (int) $stationRow['station_id'];
            $stationName = (string) $stationRow['name'];

            $before = null;
            if ($productId === null) {
                $sku = 'custom-' . bin2hex(random_bytes(8));
                $newRoutingStatus = ($routingStatus ?? ProductRoutingStatus::OwnerReview)->value;
                $insert = $this->pdo->prepare(
                    'INSERT INTO products (outlet_id, category_id, station_id, routing_status, sku, name, image_ref, vegetarian, active, available, display_order, version) '
                    . 'VALUES (:outlet, :category, :station, :routing_status, :sku, :name, \'/assets/food/snack.svg\', :vegetarian, 1, 0, :display_order, 1)'
                );
                $insert->execute([
                    'outlet' => $outletId, 'category' => $categoryId, 'station' => $stationId, 'routing_status' => $newRoutingStatus,
                    'sku' => $sku, 'name' => $name, 'vegetarian' => $vegetarian ? 1 : 0, 'display_order' => $displayOrder,
                ]);
                $productId = (int) $this->pdo->lastInsertId();
                if ($productId < 1) {
                    throw new RuntimeException('The new menu item could not be saved.');
                }
                $createdProductId = $productId;
                $this->syncVariants($productId, $variants, []);
                $after = $this->productSummary($name, $categoryName, $stationName, false, $variants, $displayOrder, $vegetarian, $newRoutingStatus);
                $this->audit($outletId, 'product_created', 'product', (string) $productId, null, $after);
                return;
            }

            $lock = $this->pdo->prepare('SELECT p.version, p.name, p.routing_status, c.name AS category_name, ps.name AS station_name, p.available, p.active, p.display_order, p.vegetarian '
                . 'FROM products p JOIN categories c ON c.category_id = p.category_id AND c.outlet_id = p.outlet_id '
                . 'JOIN preparation_stations ps ON ps.station_id = p.station_id AND ps.outlet_id = p.outlet_id '
                . 'WHERE p.product_id = :id AND p.outlet_id = :outlet' . $this->forUpdate());
            $lock->execute(['id' => $productId, 'outlet' => $outletId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || (int) $current['version'] !== $expectedVersion) {
                throw new DomainException('This menu item changed in another tab. Reload before saving.');
            }
            $beforeVariants = $this->variantSnapshot($productId);
            $effectiveRoutingStatus = $routingStatus?->value ?? (string) $current['routing_status'];
            $available = $availableRequested && $effectiveRoutingStatus === ProductRoutingStatus::Approved->value;
            $before = $this->productSummary((string) $current['name'], (string) $current['category_name'], (string) $current['station_name'], (int) $current['available'] === 1, $beforeVariants, (int) $current['display_order'], (int) $current['vegetarian'] === 1, (string) $current['routing_status']);
            $update = $this->pdo->prepare(
                'UPDATE products SET category_id = :category, station_id = :station, routing_status = :routing_status, name = :name, vegetarian = :vegetarian, '
                . 'available = :available, display_order = :display_order, version = version + 1 '
                . 'WHERE product_id = :id AND outlet_id = :outlet AND version = :version'
            );
            $update->execute([
                'category' => $categoryId, 'station' => $stationId, 'routing_status' => $effectiveRoutingStatus, 'name' => $name,
                'vegetarian' => $vegetarian ? 1 : 0, 'available' => $available ? 1 : 0,
                'display_order' => $displayOrder, 'id' => $productId, 'outlet' => $outletId, 'version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This menu item changed in another tab. Reload before saving.');
            }
            $existing = $this->pdo->prepare('SELECT variant_id, code FROM product_variants WHERE product_id = :product' . $this->forUpdate());
            $existing->execute(['product' => $productId]);
            $existingRows = $existing->fetchAll(PDO::FETCH_ASSOC);
            $this->syncVariants($productId, $variants, $existingRows);
            $after = $this->productSummary($name, $categoryName, $stationName, $available, $variants, $displayOrder, $vegetarian, $effectiveRoutingStatus);
            $this->audit($outletId, 'product_updated', 'product', (string) $productId, $before, $after);
        });
        return $createdProductId ?? (int) $productId;
    }

    public function setAvailability(string $outletCode, int $productId, int $expectedVersion, bool $available): void
    {
        $this->changeProductState(
            $outletCode, $productId, $expectedVersion, $available ? 'availability_on' : 'availability_off',
            static fn(array $row): array => ['available' => $available ? 1 : 0, 'active' => (int) $row['active']],
        );
    }

    public function archive(string $outletCode, int $productId, int $expectedVersion): void
    {
        $this->changeProductState($outletCode, $productId, $expectedVersion, 'product_archived', static fn(array $row): array => ['active' => 0, 'available' => 0]);
    }

    public function restore(string $outletCode, int $productId, int $expectedVersion): void
    {
        $this->changeProductState($outletCode, $productId, $expectedVersion, 'product_restored', static fn(array $row): array => ['active' => 1, 'available' => 0]);
    }

    /** @return list<array<string, mixed>> */
    public function categoriesForAdmin(string $outletCode): array
    {
        return $this->categories($outletCode);
    }

    public function saveCategory(
        string $outletCode,
        ?string $slug,
        int $expectedVersion,
        string $name,
        bool $active,
        int $sortOrder,
        ?string $defaultDepartmentCode = null,
    ): void
    {
        $name = $this->plainText($name, 120, 'Category name');
        if ($defaultDepartmentCode !== null && OperationalDepartment::tryFrom($defaultDepartmentCode) === null) {
            throw new DomainException('Choose a valid default preparation department.');
        }
        if ($sortOrder < 0 || $sortOrder > 9999) {
            throw new DomainException('Category order must be between 0 and 9999.');
        }
        $this->transaction(function () use ($outletCode, $slug, $expectedVersion, $name, $active, $sortOrder, $defaultDepartmentCode): void {
            $outletId = $this->outletId($outletCode);
            // Serialize category saves so concurrent requests cannot create duplicate names.
            $outletLock = $this->pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_id = ?' . $this->forUpdate());
            $outletLock->execute([$outletId]);
            $outletLock->fetchColumn();
            $duplicateSql = 'SELECT category_id FROM categories WHERE outlet_id = :outlet AND LOWER(TRIM(name)) = LOWER(TRIM(:name))';
            $duplicateParams = ['outlet' => $outletId, 'name' => $name];
            if ($slug !== null) {
                $duplicateSql .= ' AND slug <> :slug';
                $duplicateParams['slug'] = $slug;
            }
            $duplicate = $this->pdo->prepare($duplicateSql);
            $duplicate->execute($duplicateParams);
            if ($duplicate->fetchColumn() !== false) {
                throw new DomainException('A category with this name already exists. Edit the existing category instead.');
            }
            if ($slug === null) {
                $newSlug = $this->makeSlug($name);
                $insert = $this->pdo->prepare('INSERT INTO categories (outlet_id, slug, name, default_department_code, active, sort_order, version) VALUES (:outlet, :slug, :name, :department, :active, :order, 1)');
                $insert->execute(['outlet' => $outletId, 'slug' => $newSlug, 'name' => $name, 'department' => $defaultDepartmentCode, 'active' => $active ? 1 : 0, 'order' => $sortOrder]);
                $id = (string) $this->pdo->lastInsertId();
                $this->audit($outletId, 'category_created', 'category', $id, null, $name . ' · ' . ($active ? 'visible' : 'hidden') . ' · order ' . $sortOrder . ' · department ' . ($defaultDepartmentCode ?? 'unassigned'));
                return;
            }
            $lock = $this->pdo->prepare('SELECT category_id, version, name, default_department_code, active, sort_order FROM categories WHERE outlet_id = :outlet AND slug = :slug' . $this->forUpdate());
            $lock->execute(['outlet' => $outletId, 'slug' => $slug]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['version'] !== $expectedVersion) {
                throw new DomainException('This category changed in another tab. Reload before saving.');
            }
            $update = $this->pdo->prepare('UPDATE categories SET name = :name, default_department_code = :department, active = :active, sort_order = :order, version = version + 1 WHERE category_id = :id AND outlet_id = :outlet AND version = :version');
            $update->execute(['name' => $name, 'department' => $defaultDepartmentCode, 'active' => $active ? 1 : 0, 'order' => $sortOrder, 'id' => (int) $row['category_id'], 'outlet' => $outletId, 'version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This category changed in another tab. Reload before saving.');
            }
            $before = (string) $row['name'] . ' · ' . ((int) $row['active'] === 1 ? 'visible' : 'hidden') . ' · order ' . (int) $row['sort_order'] . ' · department ' . ((string) ($row['default_department_code'] ?? '') ?: 'unassigned');
            $after = $name . ' · ' . ($active ? 'visible' : 'hidden') . ' · order ' . $sortOrder . ' · department ' . ($defaultDepartmentCode ?? 'unassigned');
            $this->audit($outletId, 'category_updated', 'category', (string) $row['category_id'], $before, $after);
        });
    }

    public function saveStationDepartment(string $outletCode, string $stationCode, int $expectedVersion, ?string $departmentCode): void
    {
        if ($expectedVersion < 1 || PreparationStation::tryFrom($stationCode) === null
            || ($departmentCode !== null && OperationalDepartment::tryFrom($departmentCode) === null)) {
            throw new DomainException('Choose a valid station and preparation department.');
        }
        $this->transaction(function () use ($outletCode, $stationCode, $expectedVersion, $departmentCode): void {
            $outletId = $this->outletId($outletCode);
            $lock = $this->pdo->prepare(
                'SELECT station_id, department_code, department_version FROM preparation_stations '
                . 'WHERE outlet_id = :outlet AND code = :code AND active = 1' . $this->forUpdate()
            );
            $lock->execute(['outlet' => $outletId, 'code' => $stationCode]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['department_version'] !== $expectedVersion) {
                throw new DomainException('The selected preparation station is unavailable.');
            }
            $update = $this->pdo->prepare(
                'UPDATE preparation_stations SET department_code = :department, department_version = department_version + 1 '
                . 'WHERE outlet_id = :outlet AND station_id = :station AND department_version = :version'
            );
            $update->execute([
                'department' => $departmentCode,
                'outlet' => $outletId,
                'station' => (int) $row['station_id'],
                'version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The station assignment changed in another tab. Reload before saving.');
            }
            $before = (string) ($row['department_code'] ?? '') ?: 'unassigned';
            $after = $departmentCode ?? 'unassigned';
            if ($before !== $after) {
                $this->audit($outletId, 'station_department_updated', 'station', $stationCode, $before, $after);
            }
        });
    }

    public function preparationNotice(string $outletCode): string
    {
        return (string) ($this->preparationSetting($outletCode)['preparation_notice'] ?? '15–20 mins');
    }

    /** @return array{preparation_notice: string, version: int} */
    public function preparationSetting(string $outletCode): array
    {
        $query = $this->pdo->prepare('SELECT s.preparation_notice, s.version FROM outlet_menu_settings s JOIN outlets o ON o.outlet_id = s.outlet_id WHERE o.outlet_code = :outlet');
        $query->execute(['outlet' => $outletCode]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['preparation_notice' => (string) $row['preparation_notice'], 'version' => (int) $row['version']]
            : ['preparation_notice' => '15–20 mins', 'version' => 0];
    }

    public function savePreparationNotice(string $outletCode, int $expectedVersion, string $notice): void
    {
        $notice = $this->plainText($notice, 80, 'Preparation time');
        $this->transaction(function () use ($outletCode, $expectedVersion, $notice): void {
            $outletId = $this->outletId($outletCode);
            $lock = $this->pdo->prepare('SELECT preparation_notice, version FROM outlet_menu_settings WHERE outlet_id = :outlet' . $this->forUpdate());
            $lock->execute(['outlet' => $outletId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['version'] !== $expectedVersion) {
                throw new DomainException('The preparation notice changed in another tab. Reload before saving.');
            }
            $update = $this->pdo->prepare('UPDATE outlet_menu_settings SET preparation_notice = :notice, version = version + 1 WHERE outlet_id = :outlet AND version = :version');
            $update->execute(['notice' => $notice, 'outlet' => $outletId, 'version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The preparation notice changed in another tab. Reload before saving.');
            }
            $this->audit($outletId, 'preparation_notice_updated', 'settings', (string) $outletId, (string) $row['preparation_notice'], $notice);
        });
    }

    /** @return list<array<string, mixed>> */
    public function recentChanges(string $outletCode, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->pdo->prepare('SELECT e.action, e.entity_type, e.entity_id, e.before_summary, e.after_summary, e.actor_ref, e.created_at '
            . 'FROM menu_admin_events e JOIN outlets o ON o.outlet_id = e.outlet_id WHERE o.outlet_code = :outlet '
            . 'ORDER BY e.event_id DESC LIMIT ' . $limit);
        $query->execute(['outlet' => $outletCode]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $fields */
    public function updateImage(string $outletCode, int $productId, int $expectedVersion, string $imageReference, int $width, int $height): void
    {
        if (preg_match('~^/assets/menu-managed/[a-z0-9][a-z0-9_-]{0,79}/[a-f0-9]{16,64}$~D', $imageReference) !== 1
            || $width < 1 || $height < 1) {
            throw new DomainException('The image could not be saved safely.');
        }
        $this->transaction(function () use ($outletCode, $productId, $expectedVersion, $imageReference, $width, $height): void {
            $outletId = $this->outletId($outletCode);
            $lock = $this->pdo->prepare('SELECT p.name, p.version FROM products p WHERE p.product_id = :id AND p.outlet_id = :outlet' . $this->forUpdate());
            $lock->execute(['id' => $productId, 'outlet' => $outletId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['version'] !== $expectedVersion) {
                throw new DomainException('This menu item changed in another tab. Reload before changing its image.');
            }
            $update = $this->pdo->prepare('UPDATE products SET image_ref = :image, image_width = :width, image_height = :height, version = version + 1 WHERE product_id = :id AND outlet_id = :outlet AND version = :version');
            $update->execute(['image' => $imageReference, 'width' => $width, 'height' => $height, 'id' => $productId, 'outlet' => $outletId, 'version' => $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This menu item changed in another tab. Reload before changing its image.');
            }
            $this->audit($outletId, 'image_replaced', 'product', (string) $productId, 'Previous image', 'Image updated for ' . (string) $row['name']);
        });
    }

    /** @param callable(array<string, mixed>): array<string, int> $newState */
    private function changeProductState(string $outletCode, int $productId, int $expectedVersion, string $action, callable $newState): void
    {
        $this->transaction(function () use ($outletCode, $productId, $expectedVersion, $action, $newState): void {
            $outletId = $this->outletId($outletCode);
            $lock = $this->pdo->prepare('SELECT name, active, available, routing_status, version FROM products WHERE product_id = :id AND outlet_id = :outlet' . $this->forUpdate());
            $lock->execute(['id' => $productId, 'outlet' => $outletId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['version'] !== $expectedVersion) {
                throw new DomainException('This menu item changed in another tab. Reload before updating it.');
            }
            $state = $newState(['active' => (int) $row['active'] === 1, 'available' => (int) $row['available'] === 1]);
            if ($action === 'availability_on' && (int) $row['active'] !== 1) {
                throw new DomainException('Restore this archived item before making it available.');
            }
            $update = $this->pdo->prepare('UPDATE products SET active = :active, available = :available, version = version + 1 WHERE product_id = :id AND outlet_id = :outlet AND version = :version');
            $update->execute([
                'active' => $state['active'], 'available' => $state['available'], 'id' => $productId,
                'outlet' => $outletId, 'version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This menu item changed in another tab. Reload before updating it.');
            }
            $before = (int) $row['active'] === 1 && (int) $row['available'] === 1 ? 'Available' : 'Hidden';
            $after = $state['active'] === 1 && $state['available'] === 1 ? 'Available' : 'Hidden';
            $this->audit($outletId, $action, 'product', (string) $productId, $before . ' · ' . (string) $row['name'], $after . ' · ' . (string) $row['name']);
        });
    }

    /** @param list<array<string, mixed>> $existing */
    private function syncVariants(int $productId, array $variants, array $existing): void
    {
        $existingById = [];
        foreach ($existing as $row) {
            $existingById[(int) $row['variant_id']] = $row;
        }
        $order = 0;
        $used = [];
        foreach ($variants as $variant) {
            $label = $this->plainText((string) $variant['label'], 100, 'Serving name');
            $unit = QuantityUnit::from((string) $variant['unit']);
            $quantity = (int) $variant['quantity_value'];
            if ($quantity < 1 || $quantity > 100_000) {
                throw new DomainException('Serving quantity must be between 1 and 100000.');
            }
            $id = isset($variant['id']) ? (int) $variant['id'] : 0;
            if ($id > 0) {
                if (!isset($existingById[$id])) {
                    throw new DomainException('A serving option does not belong to this menu item.');
                }
                $used[$id] = true;
                $update = $this->pdo->prepare('UPDATE product_variants SET label = :label, unit = :unit, quantity_value = :quantity, price_paise = :price, active = :active, display_order = :display_order, version = version + 1 WHERE variant_id = :id AND product_id = :product');
                $update->execute([
                    'label' => $label, 'unit' => $unit->value, 'quantity' => $quantity, 'price' => $variant['price']->paise(),
                    'active' => !empty($variant['active']) ? 1 : 0, 'display_order' => $order++, 'id' => $id, 'product' => $productId,
                ]);
            } else {
                $code = 'option-' . bin2hex(random_bytes(5));
                $insert = $this->pdo->prepare('INSERT INTO product_variants (product_id, code, label, unit, quantity_value, price_paise, active, display_order, version) VALUES (:product, :code, :label, :unit, :quantity, :price, :active, :display_order, 1)');
                $insert->execute([
                    'product' => $productId, 'code' => $code, 'label' => $label, 'unit' => $unit->value, 'quantity' => $quantity,
                    'price' => $variant['price']->paise(), 'active' => !empty($variant['active']) ? 1 : 0, 'display_order' => $order++,
                ]);
            }
        }
        foreach ($existingById as $id => $row) {
            if (!isset($used[$id])) {
                $deactivate = $this->pdo->prepare('UPDATE product_variants SET active = 0, version = version + 1 WHERE variant_id = :id AND product_id = :product');
                $deactivate->execute(['id' => $id, 'product' => $productId]);
            }
        }
    }

    /** @param list<array<string, mixed>> $variants */
    private function productSummary(string $name, string $category, string $station, bool $available, array $variants, int $displayOrder, bool $vegetarian, string $routingStatus): string
    {
        $servings = [];
        foreach ($variants as $variant) {
            if (empty($variant['active']) || !(($variant['price'] ?? null) instanceof Money)) {
                continue;
            }
            $servings[] = (string) $variant['label'] . ' ' . $variant['price']->formatRupees();
        }
        return substr($name . ' · ' . $category . ' · ' . $station . ' · route ' . $routingStatus . ' · ' . ($available ? 'available' : 'hidden')
            . ' · order ' . $displayOrder . ' · ' . ($vegetarian ? 'vegetarian' : 'not marked vegetarian')
            . ($servings !== [] ? ' · ' . implode(', ', $servings) : ''), 0, 500);
    }

    /** @return list<array{label: string, price: Money, active: bool}> */
    private function variantSnapshot(int $productId): array
    {
        $query = $this->pdo->prepare('SELECT label, price_paise, active FROM product_variants WHERE product_id = :product ORDER BY display_order, variant_id');
        $query->execute(['product' => $productId]);
        $variants = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variants[] = [
                'label' => (string) $row['label'], 'price' => Money::fromPaise((int) $row['price_paise']),
                'active' => (int) $row['active'] === 1,
            ];
        }
        return $variants;
    }

    private function audit(int $outletId, string $action, string $entityType, string $entityId, ?string $before, string $after): void
    {
        $query = $this->pdo->prepare('INSERT INTO menu_admin_events (outlet_id, action, entity_type, entity_id, before_summary, after_summary, actor_ref) VALUES (:outlet, :action, :type, :id, :before, :after, :actor)');
        $query->execute([
            'outlet' => $outletId, 'action' => substr($action, 0, 40), 'type' => substr($entityType, 0, 24),
            'id' => substr($entityId, 0, 80), 'before' => $before === null ? null : substr($before, 0, 500),
            'after' => substr($after, 0, 500), 'actor' => $this->auditActor,
        ]);
    }

    private function outletId(string $outletCode): int
    {
        $query = $this->pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :outlet AND active = 1');
        $query->execute(['outlet' => $outletCode]);
        $id = $query->fetchColumn();
        if (!is_string($id) && !is_int($id)) {
            throw new DomainException('The selected menu is unavailable.');
        }
        return (int) $id;
    }

    private function plainText(string $value, int $max, string $label): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $max || str_contains($value, '<') || str_contains($value, '>')) {
            throw new DomainException($label . ' must be plain text between 1 and ' . $max . ' characters.');
        }
        return $value;
    }

    private function slug(string $slug): string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new DomainException('Choose a valid menu category.');
        }
        return $slug;
    }

    private function makeSlug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
        if ($slug === '') {
            $slug = 'category';
        }
        $slug = substr($slug, 0, 64);
        return $slug . '-' . bin2hex(random_bytes(3));
    }

    private function boundedInt(mixed $value, int $min, int $max, string $label): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $value) === 1) {
            $number = (int) $value;
        } else {
            throw new DomainException($label . ' must be a whole number.');
        }
        if ($number < $min || $number > $max) {
            throw new DomainException($label . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return $number;
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function transaction(callable $callback): void
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $callback();
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
