<?php
declare(strict_types=1);

namespace Kios\MerchantOperations;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kios\Domain\{Order, PaymentMethod, PreparationTaskStatus, PrintJobStatus};
use Kios\Infrastructure\{OrderRepository, PreparationQueueService, PreparationWorkflowService, PrintJobService, ShopOperations};
use Kios\MerchantFoundation\{AuthenticatedUserProvider, AuthorizationService, PdoIdentityStore, Permission, Role, TenantContext};

/** Internal tenant operational entry point. Its adapter factory permits virtual printing only. */
final class TenantOperations
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthenticatedUserProvider $authentication,
    ) {
    }

    private function rows(string $sql, array $parameters): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($parameters);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scope(string $merchant, string $outlet, Permission $permission): array
    {
        $context = (new AuthorizationService($this->authentication, new PdoIdentityStore($this->db)))
            ->requireResource($permission, $merchant, $outlet, $merchant, $outlet);
        $mapping = $this->rows(
            'SELECT m.legacy_outlet_id FROM merchant_legacy_outlets m
             JOIN outlets o ON o.outlet_id=m.legacy_outlet_id AND o.active=1
             WHERE m.merchant_id=? AND m.merchant_outlet_id=?', [$merchant, $outlet],
        );
        if (count($mapping) !== 1) {
            throw new DomainException('Explicit active outlet mapping required.');
        }
        (new Entitlements($this->db,$this->authentication))->requireFeature($merchant,$outlet,'kitchen','drain-read');return [$context, (int)$mapping[0]['legacy_outlet_id']];
    }

    private function station(TenantContext $context, int $legacy, int $station, bool $lock = false): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $rows = $this->rows(
            'SELECT station_id,code FROM preparation_stations WHERE outlet_id=? AND station_id=? AND active=1' . $suffix,
            [$legacy, $station],
        );
        if ($rows === []) {
            throw new DomainException('Station ownership denied.');
        }
        if (!$context->allows(Permission::ManageOutlet)) {
            $grants = $this->rows(
                'SELECT active FROM merchant_kitchen_grants
                 WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=? AND legacy_outlet_id=? AND station_id=? AND active=1' . $suffix,
                [$context->userId, $context->merchantId, $context->outletId, $legacy, $station],
            );
            if ($grants === []) {
                throw new DomainException('Explicit kitchen station grant required.');
            }
        }
        return $rows[0];
    }

    /** Re-resolve current authority inside the legacy mutation transaction and record accepted access. */
    private function guard(
        TenantContext $context, int $legacy, Permission $permission,
        string $operation, int $resource, ?int $station = null, bool $virtual = false,
    ): void {
        if (!$this->db->inTransaction() || $this->authentication->currentUserId() !== $context->userId) {
            throw new DomainException('Current transactional authority required.');
        }
        $staff = $this->rows('SELECT active FROM merchant_staff WHERE user_id=? FOR UPDATE', [$context->userId]);
        $mapped = $this->rows(
            'SELECT mo.active AS outlet_active,m.active AS merchant_active,o.active AS legacy_active,l.legacy_outlet_id
             FROM merchant_outlets mo JOIN merchant_organizations m ON m.merchant_id=mo.merchant_id
             JOIN merchant_legacy_outlets l ON l.merchant_id=mo.merchant_id AND l.merchant_outlet_id=mo.outlet_id
             JOIN outlets o ON o.outlet_id=l.legacy_outlet_id
             WHERE mo.merchant_id=? AND mo.outlet_id=? FOR UPDATE', [$context->merchantId,$context->outletId],
        );
        if ($staff === [] || !(bool)$staff[0]['active'] || $mapped === []
            || !(bool)$mapped[0]['merchant_active'] || !(bool)$mapped[0]['outlet_active']
            || !(bool)$mapped[0]['legacy_active'] || (int)$mapped[0]['legacy_outlet_id'] !== $legacy) {
            throw new DomainException('Operational scope revoked.');
        }
        $grants = $this->rows(
            'SELECT role_code FROM merchant_memberships WHERE user_id=? AND merchant_id=? AND active=1 FOR UPDATE',
            [$context->userId,$context->merchantId],
        );
        $grants = array_merge($grants, $this->rows(
            'SELECT role_code FROM merchant_outlet_memberships WHERE user_id=? AND merchant_id=? AND outlet_id=? AND active=1 FOR UPDATE',
            [$context->userId,$context->merchantId,$context->outletId],
        ));
        $roles = array_map(static fn(array $row): Role => Role::from($row['role_code']), $grants);
        $allowed = false;
        $manage = false;
        foreach ($roles as $role) {
            $allowed = $allowed || $role->allows($permission);
            $manage = $manage || $role->allows(Permission::ManageOutlet);
        }
        if (!$allowed) {
            throw new DomainException('Operational permission revoked.');
        }
        (new Entitlements($this->db,$this->authentication))->requireFeature($context->merchantId,$context->outletId,'kitchen','drain');
        if ($station !== null) {
            // Station privilege must reflect current roles, not the earlier context snapshot.
            $rows = $this->rows('SELECT station_id FROM preparation_stations WHERE outlet_id=? AND station_id=? AND active=1 FOR UPDATE', [$legacy,$station]);
            if ($rows === [] || (!$manage && $this->rows(
                'SELECT active FROM merchant_kitchen_grants WHERE user_id=? AND merchant_id=? AND merchant_outlet_id=?
                 AND legacy_outlet_id=? AND station_id=? AND active=1 FOR UPDATE',
                [$context->userId,$context->merchantId,$context->outletId,$legacy,$station],
            ) === [])) {
                throw new DomainException('Kitchen grant revoked.');
            }
        }
        if ($virtual) {
            $printers = $this->rows('SELECT adapter_type FROM printers WHERE outlet_id=? AND enabled=1 FOR UPDATE', [$legacy]);
            foreach ($printers as $printer) {
                if ($printer['adapter_type'] !== 'virtual') {
                    throw new DomainException('Physical printing is disabled.');
                }
            }
        }
        $date = (new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $this->rows(
            'INSERT INTO merchant_operation_events(merchant_id,merchant_outlet_id,legacy_outlet_id,actor_user_id,operation,resource_id,business_date)
             VALUES(?,?,?,?,?,?,?)',
            [$context->merchantId,$context->outletId,$legacy,$context->userId,$operation,$resource,$date],
        );
    }

    private function orderRow(int $legacy, int $order): array
    {
        $rows = $this->rows('SELECT order_id,token,status FROM orders WHERE outlet_id=? AND order_id=?', [$legacy,$order]);
        if ($rows === []) {
            throw new DomainException('Order outside authorized outlet.');
        }
        return $rows[0];
    }

    /** Synthetic/local cash transition only. Existing routing and KOT generation remain authoritative. */
    public function confirmCash(string $merchant, string $outlet, int $order): Order
    {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::RecordCash);
        $row = $this->orderRow($legacy,$order);
        $repo = new OrderRepository($this->db,
            operationalGuard: fn() => $this->guard($context,$legacy,Permission::RecordCash,'cash-route-authorized',$order,null,true),
            operatorIdentity: $context->userId);
        return $repo->confirmPaymentByInternalToken($row['token'], PaymentMethod::Cash, $context->userId, $legacy);
    }

    public function queue(string $merchant, string $outlet, int $station): array
    {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::PrepareOrders);
        $row = $this->station($context,$legacy,$station);
        return (new PreparationQueueService($this->db))->forStation($legacy,$row['code']);
    }

    private function task(int $legacy, int $task): array
    {
        $rows = $this->rows(
            'SELECT pt.station_id,pt.order_id FROM preparation_tasks pt
             JOIN orders o ON o.outlet_id=pt.outlet_id AND o.order_id=pt.order_id
             WHERE pt.outlet_id=? AND pt.preparation_task_id=?', [$legacy,$task],
        );
        if ($rows === []) {
            throw new DomainException('Task outside authorized outlet.');
        }
        return $rows[0];
    }

    public function kot(string $merchant, string $outlet, int $task): string
    {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::PrepareOrders);
        $row = $this->task($legacy,$task);
        $this->station($context,$legacy,(int)$row['station_id']);
        $jobs = $this->rows(
            "SELECT payload_text FROM print_jobs WHERE outlet_id=? AND order_id=? AND preparation_task_id=?
             AND station_id=? AND job_type='STATION_KOT' ORDER BY print_job_id LIMIT 1",
            [$legacy,(int)$row['order_id'],$task,(int)$row['station_id']],
        );
        if ($jobs === []) {
            throw new DomainException('KOT snapshot unavailable.');
        }
        return $jobs[0]['payload_text'];
    }

    public function advance(string $merchant, string $outlet, int $task, PreparationTaskStatus $expected): array
    {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::PrepareOrders);
        $row = $this->task($legacy,$task);
        $station = $this->station($context,$legacy,(int)$row['station_id']);
        $workflow = new PreparationWorkflowService($this->db,
            fn() => $this->guard($context,$legacy,Permission::PrepareOrders,'prepare-authorized',$task,(int)$row['station_id']));
        return $workflow->advanceTask($task,$legacy,$station['code'],$expected,actor:$context->userId);
    }

    public function collect(string $merchant, string $outlet, int $order): Order
    {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::RecordCash);
        $this->orderRow($legacy,$order);
        $repo = new OrderRepository($this->db,
            operationalGuard: fn() => $this->guard($context,$legacy,Permission::RecordCash,'collect-authorized',$order),
            operatorIdentity:$context->userId);
        // Retain the legacy Ground Floor transition gate, with canonical actor attribution.
        return $repo->markCollectedById($order,$legacy,'ground_floor');
    }

    public function printJobs(string $merchant, string $outlet): array
    {
        [$context, $legacy] = $this->scope($merchant,$outlet,Permission::ManageOutlet);
        return (new PrintJobService($this->db,
            authorization: fn() => $this->guard($context,$legacy,Permission::ManageOutlet,'print-queue-authorized',0),
            virtualOnly:true))->listForOutlet($legacy);
    }

    public function deliverVirtual(
        string $merchant, string $outlet, int $jobId, bool $retry = false, ?int $expectedPrinter = null,
    ): PrintJobStatus {
        [$context,$legacy] = $this->scope($merchant,$outlet,Permission::ManageOutlet);
        $jobs = $this->rows('SELECT * FROM print_jobs WHERE outlet_id=? AND print_job_id=?',[$legacy,$jobId]);
        if ($jobs === []) {
            throw new DomainException('Print job outside authorized outlet.');
        }
        $job = $jobs[0];
        if ($job['adapter_type_snapshot'] !== 'virtual'
            || ($expectedPrinter !== null && (int)$job['printer_id'] !== $expectedPrinter)) {
            throw new DomainException('Virtual printer identity required.');
        }
        $printer = $this->rows("SELECT printer_id FROM printers WHERE outlet_id=? AND printer_id=? AND adapter_type='virtual' AND enabled=1",
            [$legacy,(int)$job['printer_id']]);
        if ($printer === []) {
            throw new DomainException('Printer outside authorized outlet.');
        }
        $service = new PrintJobService($this->db,
            authorization:fn() => $this->guard($context,$legacy,Permission::ManageOutlet,'virtual-delivery-authorized',$jobId,null,true),
            virtualOnly:true);
        return $retry ? $service->retry($jobId,$legacy,$context->userId)
            : $service->dispatch($jobId,$legacy,actor:$context->userId);
    }

    public function daily(string $merchant, string $outlet, string $date): array
    {
        [, $legacy] = $this->scope($merchant,$outlet,Permission::ManageOutlet);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Valid business date required.');
        }
        return [
            'orders'=>$this->rows('SELECT status,COUNT(*) AS count FROM orders WHERE outlet_id=? AND pickup_business_date=? GROUP BY status',[$legacy,$date]),
            'tasks'=>$this->rows('SELECT pt.status,COUNT(*) AS count FROM preparation_tasks pt JOIN orders o ON o.outlet_id=pt.outlet_id AND o.order_id=pt.order_id WHERE pt.outlet_id=? AND o.pickup_business_date=? GROUP BY pt.status',[$legacy,$date]),
            'prints'=>$this->rows('SELECT pj.status,COUNT(*) AS count FROM print_jobs pj JOIN orders o ON o.outlet_id=pj.outlet_id AND o.order_id=pj.order_id WHERE pj.outlet_id=? AND o.pickup_business_date=? GROUP BY pj.status',[$legacy,$date]),
            'closings'=>$this->rows('SELECT closing_id,business_date,closed_epoch,summary_json FROM shop_day_closings WHERE outlet_id=? AND business_date=?',[$legacy,$date]),
            'audit'=>$this->rows('SELECT actor_user_id,operation,resource_id,occurred_at FROM merchant_operation_events WHERE merchant_id=? AND merchant_outlet_id=? AND legacy_outlet_id=? AND business_date=? ORDER BY event_id',[$merchant,$outlet,$legacy,$date]),
        ];
    }
}