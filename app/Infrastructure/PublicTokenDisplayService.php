<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use RuntimeException;

final class PublicTokenDisplayService
{
    private const OUTLET_CODE = 'demo-outlet';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{outlet:string,tokens:list<array{token:string,publicStatus:string,outlet:string,updatedAt:string}>}
     */
    public function snapshot(): array
    {
        $outletQuery = $this->pdo->prepare(
            'SELECT outlet_id, name FROM outlets WHERE outlet_code = :outlet_code AND active = 1'
        );
        $outletQuery->execute(['outlet_code' => self::OUTLET_CODE]);
        $outlet = $outletQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($outlet)) {
            throw new RuntimeException('The local demo outlet is unavailable.');
        }

        $outletId = (int) $outlet['outlet_id'];
        $outletName = (string) $outlet['name'];
        if (ShopOperations::enabled()) {
            $operations = new ShopOperations($this->pdo);
            $snapshot = $operations->publicTokens($outletId, $outletName);
            $snapshot['shopOpen'] = $operations->state($outletId)['open'];
            return $snapshot;
        }
        $query = $this->pdo->prepare(
            "SELECT COALESCE(o.pickup_token, o.token) AS display_token, o.status AS order_status, o.updated_at "
            . 'FROM orders o JOIN outlets d ON d.outlet_id = o.outlet_id '
            . 'WHERE o.outlet_id = :outlet_id AND d.outlet_code = :outlet_code AND d.active = 1 '
            . "AND ((o.status = 'preparing' AND EXISTS ("
            . "SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id "
            . "AND pt.order_id = o.order_id AND pt.status IN ('queued', 'preparing'))) "
            . "OR (o.status = 'ready' AND EXISTS ("
            . 'SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id) '
            . "AND NOT EXISTS (SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id "
            . "AND pt.order_id = o.order_id AND pt.status <> 'ready'))) "
            . "ORDER BY CASE WHEN o.status = 'preparing' THEN 0 ELSE 1 END, "
            . "CASE WHEN o.status = 'preparing' THEN o.created_at END ASC, "
            . "CASE WHEN o.status = 'ready' THEN o.updated_at END DESC, display_token ASC"
        );
        $query->execute(['outlet_id' => $outletId, 'outlet_code' => self::OUTLET_CODE]);

        $tokens = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $tokens[] = [
                'token' => (string) $row['display_token'],
                'publicStatus' => strtoupper((string) $row['order_status']),
                'outlet' => $outletName,
                'updatedAt' => (string) $row['updated_at'],
            ];
        }

        return ['outlet' => $outletName, 'tokens' => $tokens];
    }
}
