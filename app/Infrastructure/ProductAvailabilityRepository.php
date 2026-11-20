<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;

/** Outlet-scoped product availability reads and staff updates. */
final class ProductAvailabilityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function orderableProductIds(string $outletCode): array
    {
        $query = $this->pdo->prepare(
            'SELECT p.sku FROM products p '
            . 'JOIN outlets o ON o.outlet_id = p.outlet_id '
            . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'WHERE o.outlet_code = :outlet_code AND o.active = 1 AND c.active = 1 '
            . 'AND p.active = 1 AND p.available = 1 AND EXISTS '
            . '(SELECT 1 FROM product_variants pv WHERE pv.product_id = p.product_id AND pv.active = 1) '
            . 'ORDER BY c.sort_order, p.name, p.sku'
        );
        $query->execute(['outlet_code' => $outletCode]);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, bool> Active products in only the named outlet. */
    public function statesForOutlet(string $outletCode): array
    {
        $query = $this->pdo->prepare(
            'SELECT p.sku, p.available FROM products p '
            . 'JOIN outlets o ON o.outlet_id = p.outlet_id '
            . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'WHERE o.outlet_code = :outlet_code AND o.active = 1 AND c.active = 1 AND p.active = 1 '
            . 'ORDER BY c.sort_order, p.name, p.sku'
        );
        $query->execute(['outlet_code' => $outletCode]);
        $states = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['sku'] ?? null)) {
                $states[$row['sku']] = (int) $row['available'] === 1;
            }
        }
        return $states;
    }

    /**
     * Updates a database-owned product in the requested outlet.
     * Returns false for unknown, inactive, cross-outlet, or missing products.
     */
    public function setAvailable(string $productId, bool $available, string $outletCode): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $select = $this->pdo->prepare(
                'SELECT p.available FROM products p JOIN outlets o ON o.outlet_id = p.outlet_id '
                . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
                . 'WHERE o.outlet_code = :outlet_code AND o.active = 1 AND c.active = 1 '
                . 'AND p.sku = :sku AND p.active = 1' . $lockSuffix
            );
            $select->execute(['outlet_code' => $outletCode, 'sku' => $productId]);
            $current = $select->fetchColumn();
            if ($current === false) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return false;
            }

            if ((int) $current !== ($available ? 1 : 0)) {
                $update = $this->pdo->prepare(
                    'UPDATE products SET available = :available WHERE sku = :sku AND active = 1 '
                    . 'AND outlet_id = (SELECT outlet_id FROM outlets WHERE outlet_code = :outlet_code AND active = 1)'
                );
                $update->execute([
                    'available' => $available ? 1 : 0,
                    'sku' => $productId,
                    'outlet_code' => $outletCode,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Product availability could not be saved.');
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
