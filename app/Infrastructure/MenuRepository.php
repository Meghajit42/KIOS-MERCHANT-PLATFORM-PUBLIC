<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\Category;
use Kios\Domain\Money;
use Kios\Domain\PreparationStation;
use Kios\Domain\Product;
use Kios\Domain\ProductVariant;
use Kios\Domain\QuantityUnit;
use PDO;
use RuntimeException;

/** Database-backed menu reads shared by the public ordering flow and admin preview. */
final class MenuRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Category> */
    public function customerCategories(string $outletCode): array
    {
        $query = $this->pdo->prepare(
            'SELECT c.slug, c.name, c.sort_order FROM categories c '
            . 'JOIN outlets o ON o.outlet_id = c.outlet_id '
            . 'WHERE o.outlet_code = :outlet AND o.active = 1 AND c.active = 1 '
            . 'AND EXISTS (SELECT 1 FROM products p WHERE p.outlet_id = c.outlet_id '
            . 'AND p.category_id = c.category_id AND p.active = 1 AND p.available = 1 AND p.routing_status = \'approved\' '
            . 'AND EXISTS (SELECT 1 FROM preparation_stations ps WHERE ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
            . 'AND ps.active = 1 AND (ps.department_code IN (\'GROUND_FLOOR\', \'FIRST_FLOOR_KITCHEN\') '
            . 'OR (ps.department_code IS NULL AND c.default_department_code IN (\'GROUND_FLOOR\', \'FIRST_FLOOR_KITCHEN\')))) '
            . 'AND EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.product_id AND pv.active = 1)) '
            . 'ORDER BY c.sort_order, c.name, c.category_id'
        );
        $query->execute(['outlet' => $outletCode]);
        return array_map(static fn(array $row): Category => new Category(
            (string) $row['slug'], (string) $row['name'], true, (int) $row['sort_order'],
        ), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Product> */
    public function customerProductsForCategory(string $outletCode, string $categorySlug): array
    {
        return $this->products($outletCode, $categorySlug, true);
    }

    /** Includes hidden products and inactive variants to preserve stale cart context. */
    public function findBySku(string $outletCode, string $sku): ?Product
    {
        foreach ($this->products($outletCode, null, false, $sku) as $product) {
            return $product;
        }
        return null;
    }

    /** Browsing eligibility is independent of routing approval; orderability remains unchanged. */
    public function browsingCategories(string $outletCode): array
    {
        $q=$this->pdo->prepare('SELECT DISTINCT c.slug,c.name,c.sort_order FROM categories c JOIN outlets o ON o.outlet_id=c.outlet_id JOIN products p ON p.category_id=c.category_id AND p.outlet_id=c.outlet_id WHERE o.outlet_code=? AND o.active=1 AND c.active=1 AND p.active=1 AND p.available=1 ORDER BY c.sort_order,c.name,c.slug');
        $q->execute([$outletCode]);return array_map(static fn(array $x):Category=>new Category($x['slug'],$x['name'],true,(int)$x['sort_order']),$q->fetchAll(PDO::FETCH_ASSOC));
    }
    public function browsingProductsForCategory(string $outletCode,string $slug): array
    {
        $q=$this->pdo->prepare('SELECT p.sku FROM products p JOIN outlets o ON o.outlet_id=p.outlet_id JOIN categories c ON c.category_id=p.category_id AND c.outlet_id=p.outlet_id WHERE o.outlet_code=? AND o.active=1 AND c.slug=? AND c.active=1 AND p.active=1 AND p.available=1 ORDER BY p.display_order,p.name,p.product_id');$q->execute([$outletCode,$slug]);$result=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $sku){$product=$this->findBySku($outletCode,$sku);if($product!==null)$result[]=$product;}
        return $result;
    }

    public function category(string $outletCode, string $slug): ?Category
    {
        foreach ($this->customerCategories($outletCode) as $category) {
            if ($category->id === $slug) {
                return $category;
            }
        }
        return null;
    }

    public function preparationNotice(string $outletCode): string
    {
        $query = $this->pdo->prepare(
            'SELECT s.preparation_notice FROM outlet_menu_settings s '
            . 'JOIN outlets o ON o.outlet_id = s.outlet_id WHERE o.outlet_code = :outlet AND o.active = 1'
        );
        $query->execute(['outlet' => $outletCode]);
        $notice = $query->fetchColumn();
        return is_string($notice) && trim($notice) !== '' ? $notice : '15–20 mins';
    }

    /** @return list<Product> */
    private function products(string $outletCode, ?string $categorySlug, bool $customerOnly, ?string $sku = null): array
    {
        $sql = 'SELECT p.sku, p.name, c.slug AS category_slug, o.outlet_code, ps.code AS station_code, '
            . 'p.vegetarian, p.active, p.available, p.routing_status, c.active AS category_active, p.image_ref, p.display_order, '
            . 'pv.code AS variant_code, pv.label, pv.unit, pv.quantity_value, pv.price_paise, pv.active AS variant_active, pv.display_order AS variant_order '
            . 'FROM products p JOIN outlets o ON o.outlet_id = p.outlet_id '
            . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
            . 'JOIN product_variants pv ON pv.product_id = p.product_id '
            . 'WHERE o.outlet_code = :outlet';
        $params = ['outlet' => $outletCode];
        if ($customerOnly) {
            $sql .= ' AND o.active = 1 AND c.active = 1 AND p.active = 1 AND p.available = 1 AND p.routing_status = \'approved\' '
                . 'AND ps.active = 1 AND (ps.department_code IN (\'GROUND_FLOOR\', \'FIRST_FLOOR_KITCHEN\') '
                . 'OR (ps.department_code IS NULL AND c.default_department_code IN (\'GROUND_FLOOR\', \'FIRST_FLOOR_KITCHEN\'))) AND pv.active = 1';
        }
        if ($categorySlug !== null) {
            $sql .= ' AND c.slug = :category';
            $params['category'] = $categorySlug;
        }
        if ($sku !== null) {
            $sql .= ' AND p.sku = :sku';
            $params['sku'] = $sku;
        }
        $sql .= ' ORDER BY c.sort_order, p.display_order, p.name, p.product_id, pv.display_order, pv.variant_id';
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $products = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $skuKey = (string) $row['sku'];
            if (!isset($products[$skuKey])) {
                $station = PreparationStation::tryFrom((string) $row['station_code']);
                $unit = QuantityUnit::tryFrom((string) $row['unit']);
                if ($station === null || $unit === null) {
                    throw new RuntimeException('A menu item contains an unsupported serving or kitchen station.');
                }
                $products[$skuKey] = [
                    'row' => $row,
                    'variants' => [],
                ];
            }
            $unit = QuantityUnit::tryFrom((string) $row['unit']);
            if ($unit === null) {
                throw new RuntimeException('A menu item contains an unsupported serving type.');
            }
            $products[$skuKey]['variants'][] = new ProductVariant(
                (string) $row['variant_code'], (string) $row['label'], $unit,
                (int) $row['quantity_value'], Money::fromPaise((int) $row['price_paise']), (int) $row['variant_active'] === 1,
            );
        }

        $result = [];
        foreach ($products as $entry) {
            $row = $entry['row'];
            $station = PreparationStation::tryFrom((string) $row['station_code']);
            if ($station === null) {
                throw new RuntimeException('A menu item contains an unsupported kitchen station.');
            }
            $result[] = new Product(
                (string) $row['sku'], (string) $row['name'], (string) $row['category_slug'], (string) $row['outlet_code'],
                $station, (int) $row['vegetarian'] === 1, (int) $row['active'] === 1 && (int) $row['category_active'] === 1,
                (int) $row['available'] === 1 && $row['routing_status'] === 'approved',
                $entry['variants'], $row['image_ref'] === null ? null : (string) $row['image_ref'], (int) $row['display_order'],
            );
        }
        return $result;
    }
}
