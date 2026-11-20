<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use Kios\Domain\PreparationStationCode;
use Kios\Domain\OperationalDepartment;
use Kios\Domain\PreparationTaskStatus;
use PDO;
use RuntimeException;

final class PreparationQueueService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{taskId:int,token:string,status:PreparationTaskStatus,createdAt:string,kotPrintStatus:?string,kotAdapterType:?string,items:list<array{name:string,variant:string,unit:string,quantity:int}>}>
     */
    public function forStation(int $outletId, string $stationCode, ?string $departmentCode = null): array
    {
        if ($outletId < 1) {
            throw new DomainException('A valid outlet is required for the station queue.');
        }
        $station = new PreparationStationCode($stationCode);
        if ($departmentCode !== null && OperationalDepartment::tryFrom($departmentCode) === null) {
            throw new DomainException('A valid operational department is required for this queue.');
        }
        $departmentFilter = $departmentCode === null ? '' : ' AND pt.department_code_snapshot = :department_code';
        // TIMESTAMP values are returned in the database session timezone. Preserve
        // their instant before PHP applies the application's display timezone.
        $createdEpoch = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "strftime('%s', pt.created_at)"
            : 'UNIX_TIMESTAMP(pt.created_at)';
        $query = $this->pdo->prepare(
            'SELECT o.order_id AS immutable_order_id, o.pickup_business_date, pt.preparation_task_id, pt.status, ' . $createdEpoch . ' AS created_epoch, COALESCE(o.pickup_token, o.token) AS display_token, oi.order_item_id, '
            . 'oi.product_name_snapshot, oi.variant_label_snapshot, oi.unit_snapshot, oi.quantity '
            . ', (SELECT pj.status FROM print_jobs pj WHERE pj.outlet_id = pt.outlet_id AND pj.order_id = pt.order_id '
            . 'AND pj.preparation_task_id = pt.preparation_task_id AND pj.job_type = \'STATION_KOT\' '
            . 'ORDER BY pj.print_job_id DESC LIMIT 1) AS kot_print_status '
            . ', (SELECT pj.adapter_type_snapshot FROM print_jobs pj WHERE pj.outlet_id = pt.outlet_id AND pj.order_id = pt.order_id '
            . 'AND pj.preparation_task_id = pt.preparation_task_id AND pj.job_type = \'STATION_KOT\' '
            . 'ORDER BY pj.print_job_id DESC LIMIT 1) AS kot_adapter_type '
            . 'FROM preparation_tasks pt '
            . 'JOIN preparation_stations ps ON ps.outlet_id = pt.outlet_id AND ps.station_id = pt.station_id '
            . 'JOIN orders o ON o.outlet_id = pt.outlet_id AND o.order_id = pt.order_id '
            . 'JOIN preparation_task_items pti ON pti.outlet_id = pt.outlet_id AND pti.order_id = pt.order_id '
            . 'AND pti.preparation_task_id = pt.preparation_task_id '
            . 'JOIN order_items oi ON oi.outlet_id = pti.outlet_id AND oi.order_id = pti.order_id '
            . 'AND oi.order_item_id = pti.order_item_id '
            . 'WHERE pt.outlet_id = :outlet_id AND ps.code = :station_code AND ps.active = 1 '
            . 'AND o.status = :order_status' . $departmentFilter . ' '
            . 'ORDER BY pt.created_at, display_token, oi.order_item_id'
        );
        $params = [
            'outlet_id' => $outletId,
            'station_code' => $station->value,
            'order_status' => 'preparing',
        ];
        if ($departmentCode !== null) {
            $params['department_code'] = $departmentCode;
        }
        $query->execute($params);

        $tasks = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int) $row['preparation_task_id'];
            $statusValue = is_string($row['status']) ? $row['status'] : '';
            $status = PreparationTaskStatus::tryFrom($statusValue);
            if ($status === null) {
                throw new RuntimeException('The station queue contains an invalid task status.');
            }
            if (!isset($tasks[$taskId])) {
                $tasks[$taskId] = [
                    'taskId' => $taskId,
                    'orderId'=>(int)$row['immutable_order_id'], 'businessDate'=>(string)$row['pickup_business_date'],
                    'token' => (string) $row['display_token'],
                    'status' => $status,
                    'createdAt' => (new \DateTimeImmutable('@' . $row['created_epoch']))->format(DATE_ATOM),
                    'kotPrintStatus' => is_string($row['kot_print_status'] ?? null) ? $row['kot_print_status'] : null,
                    'kotAdapterType' => is_string($row['kot_adapter_type'] ?? null) ? $row['kot_adapter_type'] : null,
                    'items' => [],
                ];
            }
            $tasks[$taskId]['items'][] = [
                'name' => (string) $row['product_name_snapshot'],
                'variant' => (string) $row['variant_label_snapshot'],
                'unit' => (string) $row['unit_snapshot'],
                'quantity' => (int) $row['quantity'],
            ];
        }
        return array_values($tasks);
    }
}
