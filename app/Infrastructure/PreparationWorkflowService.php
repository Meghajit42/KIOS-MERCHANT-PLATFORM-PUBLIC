<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use Kios\Domain\OrderStateMachine;
use Kios\Domain\OrderStatus;
use Kios\Domain\OperationalDepartment;
use Kios\Domain\PreparationStationCode;
use Kios\Domain\PreparationTaskStatus;
use PDO;
use RuntimeException;
use Throwable;

final class PreparationWorkflowService
{
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $authorization = null)
    {
    }

    /**
     * Advance one station task and update the overall order when every station is ready.
     *
     * @return array{taskStatus: PreparationTaskStatus, orderStatus: OrderStatus}
     */
    public function advanceTask(
        int $taskId,
        int $outletId,
        string $stationCode,
        PreparationTaskStatus $expectedStatus,
        ?string $departmentCode = null,
        string $actor = 'system',
    ): array
    {
        if ($taskId < 1 || $outletId < 1) {
            throw new DomainException('A valid preparation task and outlet are required.');
        }
        $station = new PreparationStationCode($stationCode);
        if ($departmentCode !== null && OperationalDepartment::tryFrom($departmentCode) === null) {
            throw new DomainException('A valid operational department is required for this station action.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $actor) !== 1) {
            throw new DomainException('A valid staff role is required for this station action.');
        }
        $departmentFilter = $departmentCode === null ? '' : ' AND pt.department_code_snapshot = :department_code';
        $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $this->pdo->beginTransaction();

        try {
            if ($this->authorization !== null) {
                ($this->authorization)();
            }
            if(ShopOperations::enabled()) (new ShopOperations($this->pdo))->serializeStaffMutation($outletId);
            $lookup = $this->pdo->prepare(
                'SELECT pt.order_id FROM preparation_tasks pt '
                . 'JOIN preparation_stations ps ON ps.station_id = pt.station_id AND ps.outlet_id = pt.outlet_id '
                . 'WHERE pt.preparation_task_id = :task_id AND pt.outlet_id = :outlet_id '
                . 'AND ps.code = :station_code AND ps.active = 1' . $departmentFilter
            );
            $lookupParams = [
                'task_id' => $taskId,
                'outlet_id' => $outletId,
                'station_code' => $station->value,
            ];
            if ($departmentCode !== null) {
                $lookupParams['department_code'] = $departmentCode;
            }
            $lookup->execute($lookupParams);
            $orderIdValue = $lookup->fetchColumn();
            if ($orderIdValue === false) {
                throw new DomainException('The preparation task was not found for this station and outlet.');
            }
            $orderId = (int) $orderIdValue;

            $orderQuery = $this->pdo->prepare(
                'SELECT status FROM orders WHERE order_id = :order_id AND outlet_id = :outlet_id' . $lockSuffix
            );
            $orderQuery->execute(['order_id' => $orderId, 'outlet_id' => $outletId]);
            $orderStatusValue = $orderQuery->fetchColumn();
            if ($orderStatusValue !== OrderStatus::Preparing->value) {
                throw new DomainException('Only an order currently being prepared can advance a station task.');
            }

            $taskQuery = $this->pdo->prepare(
                'SELECT pt.status FROM preparation_tasks pt '
                . 'JOIN preparation_stations ps ON ps.station_id = pt.station_id AND ps.outlet_id = pt.outlet_id '
                . 'WHERE pt.preparation_task_id = :task_id AND pt.outlet_id = :outlet_id '
                . 'AND pt.order_id = :order_id AND ps.code = :station_code AND ps.active = 1' . $departmentFilter . $lockSuffix
            );
            $taskParams = [
                'task_id' => $taskId,
                'outlet_id' => $outletId,
                'order_id' => $orderId,
                'station_code' => $station->value,
            ];
            if ($departmentCode !== null) {
                $taskParams['department_code'] = $departmentCode;
            }
            $taskQuery->execute($taskParams);
            $taskStatusValue = $taskQuery->fetchColumn();
            $taskStatus = is_string($taskStatusValue) ? PreparationTaskStatus::tryFrom($taskStatusValue) : null;
            if ($taskStatus === null) {
                throw new RuntimeException('The preparation task has an invalid persisted status.');
            }
            if ($taskStatus !== $expectedStatus) {
                throw new DomainException('The station task changed in another request. Reload the station queue before updating it.');
            }

            $nextStatus = match ($taskStatus) {
                PreparationTaskStatus::Queued => PreparationTaskStatus::Preparing,
                PreparationTaskStatus::Preparing => PreparationTaskStatus::Ready,
                PreparationTaskStatus::Ready => throw new DomainException('A ready task cannot advance again.'),
            };
            $taskStatus->transitionTo($nextStatus);

            $updateTask = $this->pdo->prepare(
                'UPDATE preparation_tasks SET status = :next_status, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE preparation_task_id = :task_id AND outlet_id = :outlet_id AND order_id = :order_id '
                . 'AND status = :current_status' . ($departmentCode === null ? '' : ' AND department_code_snapshot = :department_code')
            );
            $updateTask->execute([
                'next_status' => $nextStatus->value,
                'task_id' => $taskId,
                'outlet_id' => $outletId,
                'order_id' => $orderId,
                'current_status' => $taskStatus->value,
            ] + ($departmentCode === null ? [] : ['department_code' => $departmentCode]));
            if ($updateTask->rowCount() !== 1) {
                throw new RuntimeException('The station task changed before its status could be saved.');
            }

            $event = $this->pdo->prepare(
                'INSERT INTO preparation_task_events '
                . '(outlet_id, order_id, preparation_task_id, from_status, to_status, changed_by) '
                . 'VALUES (:outlet_id, :order_id, :task_id, :from_status, :to_status, :changed_by)'
            );
            $event->execute([
                'outlet_id' => $outletId,
                'order_id' => $orderId,
                'task_id' => $taskId,
                'from_status' => $taskStatus->value,
                'to_status' => $nextStatus->value,
                'changed_by' => $actor,
            ]);

            $overallStatus = $this->markOrderReadyIfAllTasksReady($orderId, $outletId, $actor);
            $this->pdo->commit();
            return ['taskStatus' => $nextStatus, 'orderStatus' => $overallStatus];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function markOrderReadyIfAllTasksReady(int $orderId, int $outletId, string $actor): OrderStatus
    {
        $lockSuffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->pdo->prepare(
            'SELECT status FROM preparation_tasks WHERE outlet_id = :outlet_id AND order_id = :order_id '
            . 'ORDER BY preparation_task_id' . $lockSuffix
        );
        $query->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
        $statuses = $query->fetchAll(PDO::FETCH_COLUMN);
        if ($statuses === [] || array_filter($statuses, static fn(mixed $status): bool => $status !== PreparationTaskStatus::Ready->value) !== []) {
            return OrderStatus::Preparing;
        }

        OrderStateMachine::transition(OrderStatus::Preparing, OrderStatus::Ready);
        $update = $this->pdo->prepare(
            'UPDATE orders SET status = :ready_status WHERE order_id = :order_id AND outlet_id = :outlet_id '
            . 'AND status = :preparing_status'
        );
        $update->execute([
            'ready_status' => OrderStatus::Ready->value,
            'order_id' => $orderId,
            'outlet_id' => $outletId,
            'preparing_status' => OrderStatus::Preparing->value,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The order could not be marked ready after all stations completed.');
        }

        $history = $this->pdo->prepare(
            'INSERT INTO order_status_history (order_id, status, changed_by) VALUES (:order_id, :status, :changed_by)'
        );
        $history->execute(['order_id' => $orderId, 'status' => OrderStatus::Ready->value, 'changed_by' => $actor]);
        return OrderStatus::Ready;
    }
}
