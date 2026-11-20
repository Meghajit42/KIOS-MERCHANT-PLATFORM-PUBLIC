<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use LogicException;
use Kios\Domain\OperationalDepartment;
use PDO;
use RuntimeException;

final class PreparationRoutingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> Sanitized reasons why a pending order cannot be confirmed safely. */
    public function paymentBlockingReasons(int $orderId, int $outletId): array
    {
        if ($orderId < 1 || $outletId < 1) {
            throw new DomainException('A valid order and outlet are required for payment routing checks.');
        }
        $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && $this->pdo->inTransaction() ? ' FOR UPDATE' : '';
        $query = $this->pdo->prepare(
            'SELECT oi.product_name_snapshot, p.routing_status, oi.department_code_snapshot, ps.active AS station_active '
            . 'FROM order_items oi '
            . 'JOIN products p ON p.outlet_id = oi.outlet_id AND p.product_id = oi.product_id '
            . 'LEFT JOIN preparation_stations ps ON ps.outlet_id = oi.outlet_id AND ps.station_id = oi.station_id_snapshot '
            . 'WHERE oi.outlet_id = :outlet_id AND oi.order_id = :order_id ORDER BY oi.order_item_id' . $lockSuffix
        );
        $query->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $reasons = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) $row['product_name_snapshot']);
            if (($row['routing_status'] ?? null) !== 'approved') {
                $reasons[] = $name . ': the preparation route is not owner-approved.';
            }
            if (OperationalDepartment::tryFrom((string) ($row['department_code_snapshot'] ?? '')) === null) {
                $reasons[] = $name . ': its saved floor assignment is missing or invalid.';
            }
            if ((int) ($row['station_active'] ?? 0) !== 1) {
                $reasons[] = $name . ': its saved preparation station is unavailable.';
            }
        }
        return array_values(array_unique($reasons));
    }

    /**
     * Persist one preparation task per active station for a paid order.
     * The caller owns the transaction so payment and routing can commit together.
     */
    public function routePaidOrder(int $orderId, int $outletId): int
    {
        return $this->routeOrder($orderId,$outletId,false);
    }

    /** Called only by the transactional internal OrderRepository path, never a payment adapter. */
    public function routeInternalServiceOrder(int $orderId,int $outletId):int
    {
        if(Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1'||Environment::get('APP_ENV')!=='demo')throw new DomainException('Internal service disabled.');
        $q=$this->pdo->prepare("SELECT order_mode FROM orders WHERE order_id=? AND outlet_id=?");$q->execute([$orderId,$outletId]);
        if($q->fetchColumn()!=='INTERNAL_SERVICE')throw new DomainException('Internal classification required.');
        return $this->routeOrder($orderId,$outletId,true);
    }

    private function routeOrder(int $orderId,int $outletId,bool $internal):int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Preparation routing requires an active transaction.');
        }

        $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $orderQuery = $this->pdo->prepare(
            'SELECT status FROM orders WHERE order_id = :order_id AND outlet_id = :outlet_id' . $lockSuffix
        );
        $orderQuery->execute(['order_id' => $orderId, 'outlet_id' => $outletId]);
        $orderStatus = $orderQuery->fetchColumn();
        if ($orderStatus !== ($internal?'draft':'paid')) {
            throw new DomainException('Only a paid order in the selected outlet can be routed.');
        }

        $paymentBlockers = $this->paymentBlockingReasons($orderId, $outletId);
        if ($paymentBlockers !== []) {
            throw new DomainException('Payment is blocked until Owner Admin corrects the preparation route: ' . implode(' ', $paymentBlockers));
        }

        $existing = $this->pdo->prepare(
            'SELECT COUNT(*) FROM preparation_tasks WHERE outlet_id = :outlet_id AND order_id = :order_id'
        );
        $existing->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $taskCount = (int) $existing->fetchColumn();
        if ($taskCount > 0) {
            $this->assertExistingRoutingComplete($orderId, $outletId);
            return $taskCount;
        }

        $groups = $this->loadItemsGroupedByStation($orderId, $outletId);
        $insertTask = $this->pdo->prepare(
            'INSERT INTO preparation_tasks (outlet_id, order_id, station_id, department_code_snapshot, status) '
            . 'VALUES (:outlet_id, :order_id, :station_id, :department_code, :status)'
        );
        $insertItem = $this->pdo->prepare(
            'INSERT INTO preparation_task_items '
            . '(outlet_id, order_id, preparation_task_id, order_item_id) '
            . 'VALUES (:outlet_id, :order_id, :preparation_task_id, :order_item_id)'
        );

        foreach ($groups as $stationId => $group) {
            $insertTask->execute([
                'outlet_id' => $outletId,
                'order_id' => $orderId,
                'station_id' => $stationId,
                'department_code' => $group['departmentCode'],
                'status' => 'queued',
            ]);
            $taskId = (int) $this->pdo->lastInsertId();
            if ($taskId < 1) {
                throw new RuntimeException('The preparation task identifier was not returned.');
            }

            foreach ($group['itemIds'] as $orderItemId) {
                $insertItem->execute([
                    'outlet_id' => $outletId,
                    'order_id' => $orderId,
                    'preparation_task_id' => $taskId,
                    'order_item_id' => $orderItemId,
                ]);
                if ($insertItem->rowCount() !== 1) {
                    throw new RuntimeException('An order item could not be assigned to its preparation task.');
                }
            }
        }

        return count($groups);
    }

    /** @return array<int, array{departmentCode:string,itemIds:list<int>}> station ID => department and order item IDs */
    private function loadItemsGroupedByStation(int $orderId, int $outletId): array
    {
        $query = $this->pdo->prepare(
            'SELECT oi.order_item_id, ps.station_id AS snapshot_station_id, oi.department_code_snapshot '
            . 'FROM order_items oi '
            . 'LEFT JOIN preparation_stations ps ON ps.station_id = oi.station_id_snapshot '
            . 'AND ps.outlet_id = oi.outlet_id AND ps.active = 1 '
            . 'WHERE oi.order_id = :order_id AND oi.outlet_id = :outlet_id '
            . 'ORDER BY oi.order_item_id'
        );
        $query->execute(['order_id' => $orderId, 'outlet_id' => $outletId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new DomainException('The paid order has no preparation items.');
        }

        $groups = [];
        $seenItemIds = [];
        foreach ($rows as $row) {
            $stationId = (int) ($row['snapshot_station_id'] ?? 0);
            $orderItemId = (int) $row['order_item_id'];
            $departmentCode = is_string($row['department_code_snapshot'] ?? null) ? (string) $row['department_code_snapshot'] : '';
            if ($stationId < 1 || $orderItemId < 1 || OperationalDepartment::tryFrom($departmentCode) === null) {
                throw new DomainException('An order item has no approved preparation station and department assignment.');
            }
            if (isset($seenItemIds[$orderItemId])) {
                throw new RuntimeException('An order item was returned more than once during preparation routing.');
            }
            $seenItemIds[$orderItemId] = true;
            $groups[$stationId] ??= ['departmentCode' => $departmentCode, 'itemIds' => []];
            if ($groups[$stationId]['departmentCode'] !== $departmentCode) {
                throw new DomainException('A station contains items assigned to different floors. Ask the owner to correct routing before confirming payment.');
            }
            $groups[$stationId]['itemIds'][] = $orderItemId;
        }
        ksort($groups, SORT_NUMERIC);
        return $groups;
    }

    private function assertExistingRoutingComplete(int $orderId, int $outletId): void
    {
        $itemCountQuery = $this->pdo->prepare(
            'SELECT COUNT(*) FROM order_items WHERE outlet_id = :outlet_id AND order_id = :order_id'
        );
        $itemCountQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $itemCount = (int) $itemCountQuery->fetchColumn();

        $linksQuery = $this->pdo->prepare(
            'SELECT COUNT(*), COUNT(DISTINCT pti.order_item_id) '
            . 'FROM preparation_task_items pti '
            . 'JOIN preparation_tasks pt ON pt.outlet_id = pti.outlet_id '
            . 'AND pt.order_id = pti.order_id AND pt.preparation_task_id = pti.preparation_task_id '
            . 'WHERE pti.outlet_id = :outlet_id AND pti.order_id = :order_id'
        );
        $linksQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $linkCounts = $linksQuery->fetch(PDO::FETCH_NUM);
        $linkCount = is_array($linkCounts) ? (int) $linkCounts[0] : 0;
        $distinctItemCount = is_array($linkCounts) ? (int) $linkCounts[1] : 0;

        $emptyTaskQuery = $this->pdo->prepare(
            'SELECT COUNT(*) FROM preparation_tasks pt '
            . 'LEFT JOIN preparation_task_items pti ON pti.outlet_id = pt.outlet_id '
            . 'AND pti.order_id = pt.order_id AND pti.preparation_task_id = pt.preparation_task_id '
            . 'WHERE pt.outlet_id = :outlet_id AND pt.order_id = :order_id '
            . 'AND pti.preparation_task_item_id IS NULL'
        );
        $emptyTaskQuery->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $emptyTaskCount = (int) $emptyTaskQuery->fetchColumn();

        if ($itemCount < 1 || $linkCount !== $itemCount || $distinctItemCount !== $itemCount || $emptyTaskCount !== 0) {
            throw new RuntimeException('Existing preparation routing is incomplete or inconsistent.');
        }
    }
}
