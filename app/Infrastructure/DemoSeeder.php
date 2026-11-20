<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Application\DemoCatalog;
use Kios\Application\GopalMenuV1;
use Kios\Domain\OperationalDepartment;
use PDO;
use Throwable;

final class DemoSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{outlets: int, categories: int, stations: int, products: int, variants: int} */
    public function seed(): array
    {
        GopalMenuV1::assertIntegrity();
        $this->pdo->beginTransaction();
        try {
            $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $outletSql = $isSqlite
                ? 'INSERT INTO outlets (outlet_code, name, active) VALUES (:code, :name, 1) '
                    . 'ON CONFLICT(outlet_code) DO NOTHING'
                : 'INSERT INTO outlets (outlet_code, name, active) VALUES (:code, :name, 1) '
                    . 'ON DUPLICATE KEY UPDATE outlet_code = VALUES(outlet_code)';
            $outlet = $this->pdo->prepare($outletSql);
            $outlet->execute(['code' => 'demo-outlet', 'name' => 'Synthetic Public Restaurant']);
            $outletId = $this->lookupId('SELECT outlet_id FROM outlets WHERE outlet_code = :code', 'code', 'demo-outlet');
            $settingsSql = $isSqlite
                ? 'INSERT INTO outlet_menu_settings (outlet_id, preparation_notice) VALUES (:outlet_id, \'15–20 mins\') ON CONFLICT(outlet_id) DO NOTHING'
                : 'INSERT INTO outlet_menu_settings (outlet_id, preparation_notice) VALUES (:outlet_id, \'15–20 mins\') ON DUPLICATE KEY UPDATE outlet_id = VALUES(outlet_id)';
            $this->pdo->prepare($settingsSql)->execute(['outlet_id' => $outletId]);

            $categories = DemoCatalog::categories();
            $categoryDepartmentDefaults = ['sweets' => OperationalDepartment::GroundFloor->value];
            $stationDepartmentDefaults = [
                'SWEETS' => OperationalDepartment::GroundFloor->value,
                'CHAAT' => OperationalDepartment::GroundFloor->value,
                'SOUTH_INDIAN' => OperationalDepartment::FirstFloorKitchen->value,
                'NORTH_INDIAN' => OperationalDepartment::FirstFloorKitchen->value,
                'MOMO' => OperationalDepartment::FirstFloorKitchen->value,
                'TANDOOR' => OperationalDepartment::FirstFloorKitchen->value,
                'GRAVY' => OperationalDepartment::FirstFloorKitchen->value,
                'NOODLES' => OperationalDepartment::FirstFloorKitchen->value,
                'BEVERAGE' => OperationalDepartment::FirstFloorKitchen->value,
                'GENERAL_KITCHEN' => OperationalDepartment::FirstFloorKitchen->value,
            ];
            foreach (GopalMenuV1::stationMap() as $categorySlug => $stationCode) {
                $categoryDepartmentDefaults[$categorySlug] = $stationDepartmentDefaults[$stationCode] ?? null;
            }
            $categorySql = $isSqlite
                ? 'INSERT INTO categories (outlet_id, slug, name, default_department_code, active, sort_order) '
                    . 'VALUES (:outlet_id, :slug, :name, :department_code, 1, :sort_order) '
                    . 'ON CONFLICT(outlet_id, slug) DO NOTHING'
                : 'INSERT INTO categories (outlet_id, slug, name, default_department_code, active, sort_order) '
                    . 'VALUES (:outlet_id, :slug, :name, :department_code, 1, :sort_order) '
                    . 'ON DUPLICATE KEY UPDATE slug = VALUES(slug)';
            $categoryInsert = $this->pdo->prepare($categorySql);
            foreach ($categories as $index => $category) {
                $categoryInsert->execute([
                    'outlet_id' => $outletId,
                    'slug' => $category->id,
                    'name' => $category->name,
                    'department_code' => $categoryDepartmentDefaults[$category->id] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $products = DemoCatalog::products();

            $stationNames = [];
            foreach ($products as $product) {
                $stationNames[$product->station->value] = ucwords(strtolower(str_replace('_', ' ', $product->station->value)));
            }
            $stationSql = $isSqlite
                ? 'INSERT INTO preparation_stations (outlet_id, code, name, department_code, active) VALUES (:outlet_id, :code, :name, :department_code, 1) '
                    . 'ON CONFLICT(outlet_id, code) DO NOTHING'
                : 'INSERT INTO preparation_stations (outlet_id, code, name, department_code, active) VALUES (:outlet_id, :code, :name, :department_code, 1) '
                    . 'ON DUPLICATE KEY UPDATE code = VALUES(code)';
            $stationInsert = $this->pdo->prepare($stationSql);
            foreach ($stationNames as $code => $name) {
                $stationInsert->execute([
                    'outlet_id' => $outletId,
                    'code' => $code,
                    'name' => $name,
                    'department_code' => $stationDepartmentDefaults[$code] ?? null,
                ]);
            }

            $categoryIds = $this->lookupMap('SELECT category_id, slug FROM categories WHERE outlet_id = :outlet_id', 'outlet_id', $outletId);
            $stationIds = $this->lookupMap('SELECT station_id, code FROM preparation_stations WHERE outlet_id = :outlet_id', 'outlet_id', $outletId);
            $productSql = $isSqlite
                ? 'INSERT INTO products (outlet_id, category_id, station_id, routing_status, sku, name, description, image_ref, vegetarian, active, available, display_order) '
                    . 'VALUES (:outlet_id, :category_id, :station_id, :routing_status, :sku, :name, :description, :image_ref, :vegetarian, 1, 1, :display_order) '
                    . 'ON CONFLICT(outlet_id, sku) DO NOTHING'
                : 'INSERT INTO products (outlet_id, category_id, station_id, routing_status, sku, name, description, image_ref, vegetarian, active, available, display_order) '
                    . 'VALUES (:outlet_id, :category_id, :station_id, :routing_status, :sku, :name, :description, :image_ref, :vegetarian, 1, 1, :display_order) '
                    . 'ON DUPLICATE KEY UPDATE sku = VALUES(sku)';
            $productInsert = $this->pdo->prepare($productSql);
            $variantSql = $isSqlite
                ? 'INSERT INTO product_variants (product_id, code, label, unit, quantity_value, price_paise, active, display_order) '
                    . 'VALUES (:product_id, :code, :label, :unit, :quantity_value, :price_paise, 1, :display_order) '
                    . 'ON CONFLICT(product_id, code) DO NOTHING'
                : 'INSERT INTO product_variants (product_id, code, label, unit, quantity_value, price_paise, active, display_order) '
                    . 'VALUES (:product_id, :code, :label, :unit, :quantity_value, :price_paise, 1, :display_order) '
                    . 'ON DUPLICATE KEY UPDATE code = VALUES(code)';
            $variantInsert = $this->pdo->prepare($variantSql);
            $variantCount = 0;
            foreach ($products as $productIndex => $product) {
                if (!isset($categoryIds[$product->categoryId], $stationIds[$product->station->value])) {
                    throw new \RuntimeException('A demo menu product references an unknown category or station.');
                }
                $productInsert->execute([
                    'outlet_id' => $outletId,
                    'category_id' => $categoryIds[$product->categoryId],
                    'station_id' => $stationIds[$product->station->value],
                    'routing_status' => in_array($product->categoryId, ['dahi', 'extra', 'snacks'], true)
                        ? 'owner_review'
                        : (in_array($product->categoryId, ['sweets', 'chat'], true) ? 'approved' : 'proposed'),
                    'sku' => $product->id,
                    'name' => $product->name,
                    'description' => 'Gopal demo catalogue item; not merchant production.',
                    'image_ref' => $product->imageReference,
                    'vegetarian' => $product->vegetarian ? 1 : 0,
                    'display_order' => $productIndex,
                ]);
                $productId = $this->lookupId('SELECT product_id FROM products WHERE outlet_id = :outlet_id AND sku = :sku', 'outlet_id', $outletId, 'sku', $product->id);
                foreach ($product->variants() as $variantIndex => $variant) {
                    $variantInsert->execute([
                        'product_id' => $productId,
                        'code' => $variant->id,
                        'label' => $variant->label,
                        'unit' => $variant->unit->value,
                        'quantity_value' => $variant->quantityValue,
                        'price_paise' => $variant->unitPrice->paise(),
                        'display_order' => $variantIndex,
                    ]);
                    $variantCount++;
                }
            }

            $this->verifyPersistedCatalog($outletId, $categories, $products);
            $this->pdo->commit();
            return [
                'outlets' => 1,
                'categories' => count($categories),
                'stations' => count($stationNames),
                'products' => count($products),
                'variants' => $variantCount,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function lookupId(string $sql, string $key, int|string $value, ?string $secondKey = null, int|string|null $secondValue = null): int
    {
        $query = $this->pdo->prepare($sql);
        $params = [$key => $value];
        if ($secondKey !== null) {
            $params[$secondKey] = $secondValue;
        }
        $query->execute($params);
        $value = $query->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('A demo catalogue reference was not persisted.');
        }
        return (int) $value;
    }

    /** @return array<string, int> */
    private function lookupMap(string $sql, string $key, int|string $value): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute([$key => $value]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $values = array_values($row);
            $map[(string) $values[1]] = (int) $values[0];
        }
        return $map;
    }

    /** @param list<\Kios\Domain\Category> $categories @param list<\Kios\Domain\Product> $products */
    private function verifyPersistedCatalog(int $outletId, array $categories, array $products): void
    {
        $categoryCheck = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE outlet_id = :outlet_id AND slug = :slug');
        foreach ($categories as $category) {
            $categoryCheck->execute(['outlet_id' => $outletId, 'slug' => $category->id]);
            if ((int) $categoryCheck->fetchColumn() !== 1) {
                throw new \RuntimeException('A baseline menu category is missing.');
            }
        }

        $productCheck = $this->pdo->prepare(
            'SELECT p.name, p.category_id, p.station_id, p.image_ref, p.vegetarian, p.active, p.available, '
            . 'c.slug AS category_slug, ps.code AS station_code '
            . 'FROM products p JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
            . 'WHERE p.outlet_id = :outlet_id AND p.sku = :sku'
        );
        $variantCheck = $this->pdo->prepare(
            'SELECT code, label, unit, quantity_value, price_paise, active FROM product_variants '
            . 'WHERE product_id = :product_id ORDER BY code'
        );
        foreach ($products as $product) {
            $productCheck->execute(['outlet_id' => $outletId, 'sku' => $product->id]);
            $row = $productCheck->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('A baseline menu item is missing.');
            }

            $productId = $this->lookupId(
                'SELECT product_id FROM products WHERE outlet_id = :outlet_id AND sku = :sku',
                'outlet_id',
                $outletId,
                'sku',
                $product->id,
            );
            $variantCheck->execute(['product_id' => $productId]);
            $actualVariants = [];
            foreach ($variantCheck->fetchAll(PDO::FETCH_ASSOC) as $variantRow) {
                $actualVariants[(string) $variantRow['code']] = [
                    'label' => (string) $variantRow['label'],
                    'unit' => (string) $variantRow['unit'],
                    'quantity_value' => (int) $variantRow['quantity_value'],
                    'price_paise' => (int) $variantRow['price_paise'],
                    'active' => (int) $variantRow['active'],
                ];
            }
            $expectedVariants = [];
            foreach ($product->variants() as $variant) {
                $expectedVariants[$variant->id] = [
                    'label' => $variant->label,
                    'unit' => $variant->unit->value,
                    'quantity_value' => $variant->quantityValue,
                    'price_paise' => $variant->unitPrice->paise(),
                    'active' => 1,
                ];
            }
            ksort($expectedVariants);
            foreach (array_keys($expectedVariants) as $expectedCode) {
                if (!isset($actualVariants[$expectedCode])) {
                    throw new \RuntimeException('A baseline serving option is missing.');
                }
            }
        }
    }
}
