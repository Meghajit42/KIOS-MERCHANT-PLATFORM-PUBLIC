<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;
use Closure;
use InvalidArgumentException;
use Kios\Domain\CheckoutIntent;
use Kios\Domain\Money;
use Kios\Domain\Order;
use Kios\Domain\OrderItem;
use Kios\Domain\OrderStateMachine;
use Kios\Domain\OrderStatus;
use Kios\Domain\OrderType;
use Kios\Domain\OperationalDepartment;
use Kios\Domain\PaymentMethod;
use Kios\Domain\QuantityUnit;
use Kios\Domain\TokenGenerator;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class OrderRepository
{
    /** @param null|Closure():DateTimeImmutable $businessClock */
    public function __construct(
        private readonly PDO $pdo,
        private readonly TokenGenerator $tokens = new TokenGenerator(),
        private readonly ?Closure $businessClock = null,
        private readonly ?int $scopeOutletId = null,
        private readonly ?Closure $checkoutAuthorization = null,
        private readonly ?Closure $operationalGuard = null,
        private readonly ?string $operatorIdentity = null,
    ) {
        if ($scopeOutletId !== null && ($scopeOutletId < 1 || $checkoutAuthorization === null)) {
            throw new InvalidArgumentException('Scoped checkout requires a valid outlet and authority guard.');
        }
    }

    /** @param array<string, array<string, int>> $cart */
    public function createFromCart(array $cart, OrderType $type): Order
    {
        return $this->persistCart($cart, $type, null);
    }

    /** @param array<string, array<string, int>> $cart */
    public function createFromCheckoutIntent(
        array $cart,
        OrderType $type,
        string $idempotencyKey,
        string $sessionId,
        string $cartFingerprint,
        string $outletCode,
    ): Order {
        if (!CheckoutIntent::isValidKey($idempotencyKey)
            || preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $cartFingerprint) !== 1) {
            throw new InvalidArgumentException('The checkout attempt is invalid. Reload your cart before trying again.');
        }

        $keyHash = hash('sha256', $idempotencyKey, true);
        $sessionBindingHash = hash('sha256', $sessionId, true);
        $fingerprintBytes = hex2bin($cartFingerprint);
        if (!is_string($fingerprintBytes)) {
            throw new InvalidArgumentException('The checkout attempt is invalid. Reload your cart before trying again.');
        }

        if ($cart !== []) {
            $currentFingerprint = CheckoutIntent::fingerprintCart($cart, $outletCode, $type);
            if (!hash_equals($cartFingerprint, $currentFingerprint)) {
                throw new DomainException('Your cart changed. Reload it before placing the order.');
            }
        }

        $intent = [
            'keyHash' => $keyHash,
            'sessionBindingHash' => $sessionBindingHash,
            'cartFingerprint' => $fingerprintBytes,
            'outletCode' => $outletCode,
            'orderType' => $type->value,
        ];
        $existing = $this->findCheckoutIntentOrder($intent);
        if ($existing !== null) {
            return $existing;
        }
        if ($cart === []) {
            throw new InvalidArgumentException('Add an available item before confirming an order.');
        }

        return $this->persistCart($cart, $type, $intent);
    }

    /** Shared authoritative snapshot pricing for a draft inside its staff-owned transaction. */
    public function quoteInternalCart(array $cart):array
    {
        if(!$this->pdo->inTransaction()||$this->scopeOutletId===null||$this->checkoutAuthorization===null||Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1')throw new DomainException('Staff draft transaction required.');
        ($this->checkoutAuthorization)();$prices=[];$display=[];$total=Money::zero();foreach($this->snapshotCart($cart)as $row){$item=$row['item'];$key=$item->productId.'|'.$item->variantId;$prices[$key]=[$item->quantity,$item->unitPrice->paise(),$item->lineTotal()->paise()];$display[]=['key'=>$key,'name'=>$item->name,'serving'=>$item->variantLabel,'quantity'=>$item->quantity,'unitPricePaise'=>$item->unitPrice->paise(),'lineTotalPaise'=>$item->lineTotal()->paise()];$total=$total->add($item->lineTotal());}return ['prices'=>$prices,'display'=>$display,'totalPaise'=>$total->paise()];
    }

    /** Server-only nonfinancial entry point. Never selected from a checkout request field. */
    public function createInternalService(array $cart, Closure $attach): Order
    {
        if (Environment::get('KIOS_INTERNAL_SERVICE_ENABLED','0')!=='1'
            || Environment::get('APP_ENV')!=='demo' || $this->scopeOutletId===null
            || $this->checkoutAuthorization===null || $this->operatorIdentity===null) {
            throw new DomainException('Explicit internal service authority required.');
        }
        return $this->persistCart($cart, OrderType::DineIn, null, $attach);
    }

    /** @param array<string, array<string, int>> $cart @param null|array<string, mixed> $intent */
    private function persistCart(array $cart, OrderType $type, ?array $intent, ?Closure $internalAttach = null): Order
    {
        if ($cart === []) {
            throw new InvalidArgumentException('Add an available item before confirming an order.');
        }

        $this->beginAuthorizedCheckout();

        try {
            $items = $this->snapshotCart($cart);
            $outletCodes = array_values(array_unique(array_map(static fn(array $item): string => $item['outletCode'], $items)));
            if (count($outletCodes) !== 1) {
                throw new InvalidArgumentException('An order may contain items from one outlet only.');
            }
            $outletCode = $outletCodes[0];
            if ($intent !== null && ($intent['outletCode'] !== $outletCode || $intent['orderType'] !== $type->value)) {
                throw new DomainException('This checkout attempt does not match the selected cart. Reload before trying again.');
            }
            $outletId = $this->outletId($outletCode);
            $businessDate = $this->businessDate();
            $total = Money::zero();
            foreach ($items as $item) {
                $total = $total->add($item['item']->lineTotal());
            }
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $operationSettings = $internalAttach === null && ShopOperations::enabled() ? (new ShopOperations($this->pdo,$this->businessClock))->assertAccepting($outletId) : null;
                $publicId = 'O-' . strtoupper(bin2hex(random_bytes(12)));
                $token = $this->tokens->generate([]);
                $pickupToken = $internalAttach === null ? 'G-' . str_pad((string) $this->allocatePickupNumber($outletId, $businessDate), 3, '0', STR_PAD_LEFT) : null;
                $orderColumns = 'outlet_id, outlet_code_snapshot, public_id, token, pickup_token, pickup_business_date, order_type, status, total_paise';
                $orderValues = 'outlet_id, :outlet_code_snapshot, :public_id, :token, :pickup_token, :pickup_business_date, :order_type, :status, :total_paise';
                if ($internalAttach !== null) { $orderColumns .= ', order_mode'; $orderValues .= ', :internal_mode'; }
                if ($operationSettings !== null) { $orderColumns .= ', created_at, updated_at';$orderValues .= ', :operations_created, :operations_updated'; }
                if ($intent !== null) {
                    $orderColumns .= ', checkout_idempotency_key_hash, checkout_session_binding_hash, checkout_cart_fingerprint';
                    $orderValues .= ', :checkout_key_hash, :checkout_session_hash, :checkout_cart_fingerprint';
                }
                $insertOrder = $this->pdo->prepare(
                    'INSERT INTO orders (' . $orderColumns . ') SELECT ' . $orderValues . ' FROM outlets '
                    . 'WHERE outlet_id = :outlet_id AND outlet_code = :outlet_code AND active = 1'
                );
                try {
                    $orderParameters = [
                        'outlet_code_snapshot' => $outletCode,
                        'public_id' => $publicId,
                        'token' => $token,
                        'pickup_token' => $pickupToken,
                        'pickup_business_date' => $businessDate,
                        'order_type' => $type->value,
                        'status' => OrderStatus::Draft->value,
                        'total_paise' => $total->paise(),
                        'outlet_id' => $outletId,
                        'outlet_code' => $outletCode,
                    ];
                    if ($internalAttach !== null) $orderParameters['internal_mode']='INTERNAL_SERVICE';
                    if ($operationSettings !== null) { $stamp=(new ShopOperations($this->pdo,$this->businessClock))->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');$orderParameters['operations_created']=$stamp;$orderParameters['operations_updated']=$stamp; }
                    if ($intent !== null) {
                        $orderParameters['checkout_key_hash'] = $intent['keyHash'];
                        $orderParameters['checkout_session_hash'] = $intent['sessionBindingHash'];
                        $orderParameters['checkout_cart_fingerprint'] = $intent['cartFingerprint'];
                    }
                    $insertOrder->execute($orderParameters);
                } catch (PDOException $error) {
                    if ($intent !== null && $this->isUniqueCheckoutIntentCollision($error)) {
                        $this->rollback();
                        $existing = $this->findCheckoutIntentOrder($intent);
                        if ($existing !== null) {
                            return $existing;
                        }
                    }
                    if ($this->isUniqueOrderCollision($error) && $attempt < 4) {
                        $this->rollback();
                        $this->beginAuthorizedCheckout();

                        continue;
                    }
                    throw $error;
                }
                if ($insertOrder->rowCount() !== 1) {
                    throw new RuntimeException('The selected outlet is not available.');
                }
                $orderId = (int) $this->pdo->lastInsertId();
                if ($operationSettings !== null) (new ShopOperations($this->pdo,$this->businessClock))->registerOrder($orderId,$outletId,$operationSettings);
                if ($orderId < 1) {
                    throw new RuntimeException('The database did not return an order identifier.');
                }

                $insertItem = $this->pdo->prepare(
                    'INSERT INTO order_items (outlet_id, order_id, product_id, variant_id, product_sku_snapshot, '
                    . 'variant_code_snapshot, product_name_snapshot, variant_label_snapshot, unit_snapshot, station_id_snapshot, department_code_snapshot, quantity, '
                    . 'unit_price_paise, line_total_paise) '
                    . 'SELECT o.outlet_id, :order_id, p.product_id, pv.variant_id, :product_sku_snapshot, '
                    . ':variant_code_snapshot, :product_name_snapshot, :variant_label_snapshot, :unit_snapshot, :station_id_snapshot, :department_code_snapshot, '
                    . ':quantity, :unit_price_paise, :line_total_paise FROM orders o '
                    . 'JOIN products p ON p.outlet_id = o.outlet_id AND p.sku = :product_sku '
                    . 'JOIN product_variants pv ON pv.product_id = p.product_id AND pv.code = :variant_code '
                    . 'WHERE o.order_id = :parent_order_id'
                );
                foreach ($items as $item) {
                    /** @var OrderItem $snapshot */
                    $snapshot = $item['item'];
                    $insertItem->execute([
                        'order_id' => $orderId,
                        'product_sku_snapshot' => $snapshot->productId,
                        'variant_code_snapshot' => $snapshot->variantId,
                        'product_name_snapshot' => $snapshot->name,
                        'variant_label_snapshot' => $snapshot->variantLabel,
                        'unit_snapshot' => $snapshot->unit->value,
                        'station_id_snapshot' => $item['stationId'],
                        'department_code_snapshot' => $item['departmentCode'],
                        'quantity' => $snapshot->quantity,
                        'unit_price_paise' => $snapshot->unitPrice->paise(),
                        'line_total_paise' => $snapshot->lineTotal()->paise(),
                        'product_sku' => $snapshot->productId,
                        'variant_code' => $snapshot->variantId,
                        'parent_order_id' => $orderId,
                    ]);
                    if ($insertItem->rowCount() !== 1) {
                        throw new RuntimeException('An order item could not be persisted.');
                    }
                }

                if ($internalAttach !== null) {
                    $taskCount=(new PreparationRoutingService($this->pdo))->routeInternalServiceOrder($orderId,$outletId);
                    if($taskCount<1)throw new RuntimeException('Internal order created no preparation work.');
                    $change=$this->pdo->prepare("UPDATE orders SET status='preparing' WHERE order_id=? AND outlet_id=? AND status='draft' AND order_mode='INTERNAL_SERVICE'");
                    $change->execute([$orderId,$outletId]);
                    if($change->rowCount()!==1)throw new RuntimeException('Internal service state changed.');
                    $this->writeHistory($orderId,OrderStatus::Draft,$this->operatorIdentity);
                    $this->writeHistory($orderId,OrderStatus::Preparing,$this->operatorIdentity);
                    $internalAttach($orderId); // table/draft/version/audit binding must commit with the shared order.
                    (new PrintJobService($this->pdo,virtualOnly:true))->enqueueInternalKots($orderId,$outletId);
                } else {
                OrderStateMachine::transition(OrderStatus::Draft, OrderStatus::AwaitingPayment);
                $transition = $this->pdo->prepare(
                    'UPDATE orders SET status = :next_status WHERE order_id = :order_id AND status = :current_status'
                );
                $transition->execute([
                    'next_status' => OrderStatus::AwaitingPayment->value,
                    'order_id' => $orderId,
                    'current_status' => OrderStatus::Draft->value,
                ]);
                if ($transition->rowCount() !== 1) {
                    throw new RuntimeException('The order could not enter the payment queue.');
                }
                $history = $this->pdo->prepare('INSERT INTO order_status_history (order_id, status) VALUES (:order_id, :status)');
                foreach ([OrderStatus::Draft, OrderStatus::AwaitingPayment] as $status) {
                    $history->execute(['order_id' => $orderId, 'status' => $status->value]);
                }
                }
                $this->pdo->commit();
                $order = $this->findById($orderId);
                return $order ?? throw new RuntimeException('The committed order could not be reloaded.');
            } catch (Throwable $error) {
                $this->rollback();
                throw $error;
            }
        }
        $this->rollback();
        throw new RuntimeException('Could not allocate a unique order token. Please retry checkout.');
    }

    private function beginAuthorizedCheckout(): void
    {
        $this->pdo->beginTransaction();
        if ($this->checkoutAuthorization !== null) {
            try {
                ($this->checkoutAuthorization)();
            } catch (Throwable $error) {
                $this->rollback();
                throw $error;
            }
        }
    }
    public function findById(int $id): ?Order
    {
        if ($id < 1) {
            return null;
        }
        $query = $this->pdo->prepare($this->orderSelect() . ' WHERE o.order_id = :order_id' . ($this->scopeOutletId === null ? '' : ' AND o.outlet_id = :scope_outlet'));
        $parameters = ['order_id' => $id];
        if ($this->scopeOutletId !== null) {
            $parameters['scope_outlet'] = $this->scopeOutletId;
        }
        $query->execute($parameters);
        $row = $query->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Order> Most recent unpaid orders for the authenticated cashier work queue. */
    public function awaitingPaymentForOutlet(int $outletId, int $limit = 50): array
    {
        if ($outletId < 1) {
            throw new InvalidArgumentException('A valid outlet is required for the cashier queue.');
        }
        $limit = max(1, min(100, $limit));
        $query = $this->pdo->prepare(
            'SELECT order_id FROM orders WHERE outlet_id = :outlet_id AND status = :status '
            . 'ORDER BY created_at, order_id LIMIT ' . $limit
        );
        $query->execute(['outlet_id' => $outletId, 'status' => OrderStatus::AwaitingPayment->value]);
        $orders = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $order = $this->findById((int) $orderId);
            if ($order !== null) {
                $orders[] = $order;
            }
        }
        return $orders;
    }

    /** @return list<Order> Ready orders with every required preparation task complete. */
    public function readyForCollectionForOutlet(int $outletId, int $limit = 50): array
    {
        if ($outletId < 1) {
            throw new InvalidArgumentException('A valid outlet is required for the collection queue.');
        }
        $limit = max(1, min($limit, 100));
        $query = $this->pdo->prepare(
            'SELECT o.order_id FROM orders o WHERE o.outlet_id = :outlet_id AND o.status = :ready_status '
            . 'AND EXISTS (SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id) '
            . 'AND NOT EXISTS (SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id '
            . 'AND pt.order_id = o.order_id AND pt.status <> :task_ready) '
            . 'ORDER BY o.created_at, o.order_id LIMIT :limit'
        );
        $query->bindValue(':outlet_id', $outletId, PDO::PARAM_INT);
        $query->bindValue(':ready_status', OrderStatus::Ready->value);
        $query->bindValue(':task_ready', 'ready');
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $orders = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $order = $this->findById((int) $id);
            if ($order !== null) {
                $orders[] = $order;
            }
        }
        return $orders;
    }

    /** Idempotently mark a fully prepared pickup order as collected by a Ground Floor operator. */
    public function markCollectedById(int $id,int $outletId,string $actor):Order
    {
        return $this->collectOrder('', $outletId,$actor,$id);
    }
    public function markCollectedByPickupToken(string $token, int $outletId, string $actor): Order
    {
        return $this->collectOrder($token,$outletId,$actor,null);
    }
    private function collectOrder(string $token,int $outletId,string $actor,?int $immutableId):Order
    {
        if (($immutableId===null && !preg_match('/^G-[0-9]{3,}$/D', $token)) || ($immutableId!==null && $immutableId<1) || $outletId < 1 || $actor !== 'ground_floor') {
            throw new InvalidArgumentException('A valid pickup order and Ground Floor operator are required.');
        }
        $this->pdo->beginTransaction();

        try {
            if($immutableId!==null){$filter=InternalOrderBoundary::customerFilter($this->pdo,'o');$q=$this->pdo->prepare('SELECT o.order_id FROM orders o WHERE o.order_id=? AND o.outlet_id=?'.$filter);$q->execute([$immutableId,$outletId]);if(!$q->fetchColumn())throw new DomainException('Customer collection cannot serve internal orders.');}

            if ($this->operationalGuard !== null) {
                ($this->operationalGuard)();
            }
            if(ShopOperations::enabled()) (new ShopOperations($this->pdo,$this->businessClock))->serializeStaffMutation($outletId);
            $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            if($immutableId!==null){
                $lookup=$this->pdo->prepare('SELECT order_id,status FROM orders WHERE outlet_id=? AND order_id=?'.$suffix);$lookup->execute([$outletId,$immutableId]);
            }else{
            $lookup = $this->pdo->prepare(
                'SELECT order_id, status FROM orders WHERE outlet_id = :outlet_id AND pickup_token = :token '
                . 'AND pickup_business_date = :business_date' . $suffix
            );
            $lookup->execute([
                'outlet_id' => $outletId,
                'token' => $token,
                'business_date' => $this->businessDate(),
            ]);
            }
            $rows = $lookup->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new InvalidArgumentException('The pickup token was not found for this outlet and business date.');
            }
            $row = $rows[0];
            $orderId = (int) $row['order_id'];
            if ($row['status'] === OrderStatus::Collected->value) {
                $this->pdo->commit();
                return $this->findById($orderId)
                    ?? throw new RuntimeException('The collected order could not be reloaded.');
            }
            if ($row['status'] !== OrderStatus::Ready->value) {
                throw new DomainException('Only an order with every station ready can be marked collected.');
            }

            $tasks = $this->pdo->prepare(
                "SELECT COUNT(*) AS task_count, SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready_count "
                . 'FROM preparation_tasks WHERE outlet_id = :outlet_id AND order_id = :order_id'
            );
            $tasks->execute(['outlet_id' => $outletId, 'order_id' => $orderId]);
            $taskCounts = $tasks->fetch(PDO::FETCH_ASSOC);
            if (!is_array($taskCounts) || (int) $taskCounts['task_count'] < 1
                || (int) $taskCounts['ready_count'] !== (int) $taskCounts['task_count']) {
                throw new DomainException('Collection is blocked until every required station is ready.');
            }

            OrderStateMachine::transition(OrderStatus::Ready, OrderStatus::Collected);
            $update = $this->pdo->prepare(
                'UPDATE orders SET status = :collected WHERE outlet_id = :outlet_id AND order_id = :order_id AND status = :ready'
            );
            $update->execute([
                'collected' => OrderStatus::Collected->value,
                'outlet_id' => $outletId,
                'order_id' => $orderId,
                'ready' => OrderStatus::Ready->value,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The order state changed before collection was recorded. Reload the queue.');
            }
            $this->writeHistory($orderId, OrderStatus::Collected, $this->operatorIdentity ?? $actor);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
        return $this->findById($orderId)
            ?? throw new RuntimeException('The collected order could not be reloaded.');
    }

    /** @return list<array{department: string, stations: list<string>}> */
    public function departmentRoutesForOrder(string $publicId, int $outletId): array
    {
        if ($publicId === '' || $outletId < 1) {
            return [];
        }
        $query = $this->pdo->prepare(
            'SELECT DISTINCT oi.department_code_snapshot, ps.code AS station_code, ps.name AS station_name '
            . 'FROM order_items oi JOIN orders o ON o.outlet_id = oi.outlet_id AND o.order_id = oi.order_id '
            . 'LEFT JOIN preparation_stations ps '
            . 'ON ps.outlet_id = oi.outlet_id AND ps.station_id = oi.station_id_snapshot '
            . 'WHERE o.public_id = :public_id AND o.outlet_id = :outlet_id '
            . 'ORDER BY oi.department_code_snapshot, ps.name, ps.code'
        );
        $query->execute(['public_id' => $publicId, 'outlet_id' => $outletId]);
        $routes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = is_string($row['department_code_snapshot'] ?? null) ? $row['department_code_snapshot'] : '';
            $department = \Kios\Domain\OperationalDepartment::tryFrom($code);
            $key = $department?->value ?? 'UNASSIGNED';
            $routes[$key] ??= ['department' => $department?->label() ?? 'Owner review required', 'stations' => []];
            $station = trim((string) ($row['station_name'] ?? ''));
            if ($station !== '') {
                $routes[$key]['stations'][] = $station;
            }
        }
        return array_values(array_map(static function (array $route): array {
            $route['stations'] = array_values(array_unique($route['stations']));
            return $route;
        }, $routes));
    }

    /** @return list<string> */
    public function paymentBlockingReasonsForPublicOrder(string $publicId, int $outletId): array
    {
        if ($publicId === '' || $outletId < 1) {
            return [];
        }
        $query = $this->pdo->prepare('SELECT order_id FROM orders WHERE public_id = :public_id AND outlet_id = :outlet_id');
        $query->execute(['public_id' => $publicId, 'outlet_id' => $outletId]);
        $orderId = $query->fetchColumn();
        if (!is_string($orderId) && !is_int($orderId)) {
            return [];
        }
        return (new PreparationRoutingService($this->pdo))->paymentBlockingReasons((int) $orderId, $outletId);
    }

    public function findByInternalToken(string $token, ?int $outletId = null): ?Order
    {
        if (!preg_match('/^G-[A-F0-9]{16}$/D', $token)) {
            return null;
        }
        $sql = $this->orderSelect() . ' WHERE o.token = :token';
        $params = ['token' => $token];
        if ($outletId !== null) {
            $sql .= ' AND o.outlet_id = :outlet_id';
            $params['outlet_id'] = $outletId;
        }
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $row = $query->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByPickupToken(string $token, string $outletCode, ?string $businessDate = null): ?Order
    {
        if (!preg_match('/^G-[0-9]{3,}$/D', $token)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $outletCode)) {
            return null;
        }
        $outlet = $this->pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :outlet_code AND active = 1');
        $outlet->execute(['outlet_code' => $outletCode]);
        $outletId = $outlet->fetchColumn();
        if (!is_string($outletId) && !is_int($outletId)) {
            return null;
        }
        return $this->findByPickupTokenForOutlet($token, (int) $outletId, $businessDate);
    }

    public function findByPickupTokenForOutlet(string $token, int $outletId, ?string $businessDate = null): ?Order
    {
        if (!preg_match('/^G-[0-9]{3,}$/D', $token) || $outletId < 1) {
            return null;
        }
        if ($businessDate !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, new DateTimeZone('Asia/Kolkata'));
            if ($parsed === false || $parsed->format('Y-m-d') !== $businessDate) {
                return null;
            }
        }
        $sql = $this->orderSelect()
            . ' WHERE o.pickup_token = :token AND o.pickup_business_date = :business_date AND o.outlet_id = :outlet_id';
        $params = ['token' => $token, 'business_date' => $businessDate ?? $this->businessDate(), 'outlet_id' => $outletId];
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll();
        if ($rows === [] && $businessDate === null) {
            $fallback = $this->pdo->prepare(
                $this->orderSelect()
                . " WHERE o.pickup_token = :token AND o.outlet_id = :outlet_id "
                . "AND o.status IN ('draft', 'awaiting_payment', 'paid', 'preparing', 'ready')"
            );
            $fallback->execute(['token' => $token, 'outlet_id' => $outletId]);
            $rows = $fallback->fetchAll();
        }
        if (count($rows) !== 1) {
            return null;
        }
        return $this->hydrate($rows[0]);
    }

    public function confirmPaymentByInternalToken(
        string $token,
        PaymentMethod $method,
        string $cashier = 'local-cashier',
        ?int $outletId = null,
    ): Order {
        return $this->confirmPaymentForToken($token, $method, $cashier, $outletId, false);
    }

    public function confirmPaymentByPickupToken(
        string $token,
        PaymentMethod $method,
        string $cashier = 'local-cashier',
        ?int $outletId = null,
    ): Order {
        return $this->confirmPaymentForToken($token, $method, $cashier, $outletId, true);
    }

    private function confirmPaymentForToken(
        string $token,
        PaymentMethod $method,
        string $cashier,
        ?int $outletId,
        bool $isPickupToken,
        int $deadlockRetries = 3,
    ): Order
    {
        $printJobIds = [];
        $this->pdo->beginTransaction();

        try {
            if ($this->operationalGuard !== null) {
                ($this->operationalGuard)();
            }
            if(ShopOperations::enabled()) (new ShopOperations($this->pdo,$this->businessClock))->serializeStaffMutation($outletId ?? $this->outletId('demo-outlet'));
            $row = $isPickupToken
                ? $this->lockOrderByPickupToken($token, $outletId)
                : $this->lockOrderByInternalToken($token, $outletId);
            if ($row === null) {
                throw new InvalidArgumentException('Order token was not found.');
            }
            if ($row['status'] !== OrderStatus::AwaitingPayment->value) {
                throw new DomainException('Only an order awaiting payment can be confirmed.');
            }
            if(ShopOperations::enabled()) (new ShopOperations($this->pdo,$this->businessClock))->assertPaymentCurrent((int)$row['order_id'],(int)$row['outlet_id']);
            $paymentBlockers = (new PreparationRoutingService($this->pdo))->paymentBlockingReasons(
                (int) $row['order_id'],
                (int) $row['outlet_id'],
            );
            if ($paymentBlockers !== []) {
                throw new DomainException('Payment is blocked until Owner Admin corrects the preparation route: ' . implode(' ', $paymentBlockers));
            }
            OrderStateMachine::transition(OrderStatus::AwaitingPayment, OrderStatus::Paid);
            $payment = $this->pdo->prepare(
                'INSERT INTO payment_confirmations (order_id, payment_method, amount_paise, confirmed_by) '
                . 'VALUES (:order_id, :payment_method, :amount_paise, :confirmed_by)'
            );
            $payment->execute([
                'order_id' => (int) $row['order_id'],
                'payment_method' => $method->value,
                'amount_paise' => (int) $row['total_paise'],
                'confirmed_by' => substr($cashier, 0, 80),
            ]);
            $transition = $this->pdo->prepare(
                'UPDATE orders SET status = :next_status WHERE order_id = :order_id AND status = :current_status'
            );
            $transition->execute([
                'next_status' => OrderStatus::Paid->value,
                'order_id' => (int) $row['order_id'],
                'current_status' => OrderStatus::AwaitingPayment->value,
            ]);
            if ($transition->rowCount() !== 1) {
                throw new DomainException('The order payment state changed. Reload and try again.');
            }
            $this->writeHistory((int) $row['order_id'], OrderStatus::Paid, $cashier);
            $id = (int) $row['order_id'];
            $outletId = (int) $row['outlet_id'];
            $taskCount = (new PreparationRoutingService($this->pdo))->routePaidOrder($id, $outletId);
            if ($taskCount < 1) {
                throw new RuntimeException('The paid order did not create preparation work.');
            }
            OrderStateMachine::transition(OrderStatus::Paid, OrderStatus::Preparing);
            $preparingTransition = $this->pdo->prepare(
                'UPDATE orders SET status = :next_status WHERE order_id = :order_id AND status = :current_status'
            );
            $preparingTransition->execute([
                'next_status' => OrderStatus::Preparing->value,
                'order_id' => $id,
                'current_status' => OrderStatus::Paid->value,
            ]);
            if ($preparingTransition->rowCount() !== 1) {
                throw new DomainException('The order could not enter preparation.');
            }
            $this->writeHistory($id, OrderStatus::Preparing, $cashier);
            $printJobIds = (new PrintJobService($this->pdo, authorization: $this->operationalGuard, virtualOnly: $this->operationalGuard !== null))->enqueueForOrder($id, $outletId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollback();
            if ($deadlockRetries > 0 && $error instanceof PDOException
                && ($error->errorInfo[0] ?? null) === '40001' && (int) ($error->errorInfo[1] ?? 0) === 1213) {
                // MariaDB rolled back the transaction. Restart from the order lock before any printing.
                usleep(random_int(15_000, 50_000));
                return $this->confirmPaymentForToken($token, $method, $cashier, $outletId, $isPickupToken, $deadlockRetries - 1);
            }
            throw $error;
        }
        // Payment and preparation are already durable. Printing failures stay on the queue and cannot roll them back.
        $printing = new PrintJobService($this->pdo, authorization: $this->operationalGuard, virtualOnly: $this->operationalGuard !== null);
        foreach ($printJobIds as $printJobId) {
            try {
                $printing->dispatch($printJobId, $outletId, \Kios\Domain\PrintJobAction::Print, $this->operatorIdentity);
            } catch (Throwable) {
                // A persisted queued/printing job remains visible for staff recovery.
            }
        }
        return $this->findById($id) ?? throw new RuntimeException('The order in preparation could not be reloaded.');
    }

    public function cancelPendingByInternalToken(
        string $token,
        string $cashier = 'local-cashier',
        ?int $outletId = null,
    ): Order {
        return $this->cancelPendingForToken($token, $cashier, $outletId, false);
    }

    public function cancelPendingByPickupToken(
        string $token,
        string $cashier = 'local-cashier',
        ?int $outletId = null,
    ): Order {
        return $this->cancelPendingForToken($token, $cashier, $outletId, true);
    }

    private function cancelPendingForToken(
        string $token,
        string $cashier,
        ?int $outletId,
        bool $isPickupToken,
    ): Order
    {
        $this->pdo->beginTransaction();

        try {
            $row = $isPickupToken
                ? $this->lockOrderByPickupToken($token, $outletId)
                : $this->lockOrderByInternalToken($token, $outletId);
            if ($row === null) {
                throw new InvalidArgumentException('Order token was not found.');
            }
            if ($row['status'] !== OrderStatus::AwaitingPayment->value) {
                throw new DomainException('Only an order awaiting payment can be cancelled.');
            }
            OrderStateMachine::transition(OrderStatus::AwaitingPayment, OrderStatus::Cancelled);
            $update = $this->pdo->prepare('UPDATE orders SET status = :status WHERE order_id = :id AND status = :current');
            $update->execute(['status' => OrderStatus::Cancelled->value, 'id' => (int) $row['order_id'], 'current' => OrderStatus::AwaitingPayment->value]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The order state changed. Reload and try again.');
            }
            $this->writeHistory((int) $row['order_id'], OrderStatus::Cancelled, $cashier);
            $id = (int) $row['order_id'];
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
        return $this->findById($id) ?? throw new RuntimeException('The cancelled order could not be reloaded.');
    }

    /** @param array<string, array<string, int>> $cart @return list<array{outletCode: string, stationId: int, departmentCode: string, item: OrderItem}> */
    private function snapshotCart(array $cart): array
    {
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $productQuery = $this->pdo->prepare(
            'SELECT product_id, outlet_id, sku, name, station_id, category_id, active, available '
            . 'FROM products WHERE sku = :sku' . ($this->scopeOutletId === null ? '' : ' AND outlet_id = :scope_outlet') . $suffix
        );
        $variantQuery = $this->pdo->prepare(
            'SELECT code, label, unit, price_paise, active FROM product_variants '
            . 'WHERE product_id = :product_id AND code = :variant' . $suffix
        );
        $contextQuery = $this->pdo->prepare(
            'SELECT o.outlet_code, o.active AS outlet_active, c.active AS category_active, p.routing_status, ps.active AS station_active, '
            . 'COALESCE(ps.department_code, c.default_department_code) AS department_code '
            . 'FROM products p JOIN outlets o ON o.outlet_id = p.outlet_id '
            . 'JOIN categories c ON c.outlet_id = p.outlet_id AND c.category_id = p.category_id '
            . 'LEFT JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
            . 'WHERE p.product_id = :product_id' . $suffix
        );
        $items = [];
        foreach ($cart as $productId => $variants) {
            if (!is_string($productId) || !is_array($variants) || $variants === []) {
                throw new InvalidArgumentException('The cart contains an invalid selection.');
            }
            foreach ($variants as $variantId => $quantity) {
                if (!is_string($variantId) || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                    throw new InvalidArgumentException('The cart contains an invalid quantity.');
                }
                $productParameters = ['sku' => $productId];
                if ($this->scopeOutletId !== null) {
                    $productParameters['scope_outlet'] = $this->scopeOutletId;
                }
                $productQuery->execute($productParameters);
                $products = $productQuery->fetchAll(PDO::FETCH_ASSOC);
                if (count($products) !== 1) {
                    throw new InvalidArgumentException('This item is no longer available. Please remove it from your order.');
                }
                $product = $products[0];
                if ((int) $product['active'] !== 1 || (int) $product['available'] !== 1) {
                    throw new InvalidArgumentException('This item is no longer available. Please remove it from your order.');
                }

                $variantQuery->execute(['product_id' => (int) $product['product_id'], 'variant' => $variantId]);
                $variant = $variantQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($variant) || (int) $variant['active'] !== 1) {
                    throw new InvalidArgumentException('This item is no longer available. Please remove it from your order.');
                }

                $contextQuery->execute(['product_id' => (int) $product['product_id']]);
                $context = $contextQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($context) || (int) $context['outlet_active'] !== 1 || (int) $context['category_active'] !== 1) {
                    throw new InvalidArgumentException('This item is no longer available. Please remove it from your order.');
                }
                if (($context['routing_status'] ?? null) !== 'approved') {
                    throw new InvalidArgumentException('This item is waiting for an owner to confirm its preparation route. Please remove it and ask staff for help.');
                }
                if ((int) ($context['station_active'] ?? 0) !== 1) {
                    throw new InvalidArgumentException('This item’s preparation station is unavailable. Please remove it and ask staff for help.');
                }

                $departmentCode = is_string($context['department_code'] ?? null) ? $context['department_code'] : '';
                if (OperationalDepartment::tryFrom($departmentCode) === null) {
                    throw new InvalidArgumentException('This item is waiting for an owner-assigned preparation department. Please remove it and ask staff for help.');
                }

                $unit = QuantityUnit::tryFrom((string) $variant['unit']);
                if ($unit === null) {
                    throw new RuntimeException('The selected menu item has an unsupported quantity unit.');
                }
                $items[] = [
                    'outletCode' => (string) $context['outlet_code'],
                    'stationId' => (int) $product['station_id'],
                    'departmentCode' => $departmentCode,
                    'item' => new OrderItem(
                        (string) $product['sku'],
                        (string) $variant['code'],
                        (string) $product['name'],
                        (string) $variant['label'],
                        $unit,
                        $quantity,
                        Money::fromPaise((int) $variant['price_paise']),
                    ),
                ];
            }
        }
        if ($items === []) {
            throw new InvalidArgumentException('Add an available item before confirming an order.');
        }
        return $items;
    }

    /** @return array<string, mixed>|null */
    private function lockOrderByInternalToken(string $token, ?int $outletId = null): ?array
    {
        if (!preg_match('/^G-[A-F0-9]{16}$/D', $token)) {
            throw new InvalidArgumentException('Enter a valid order token.');
        }
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sql = 'SELECT order_id, outlet_id, status, total_paise FROM orders WHERE token = :token';
        $params = ['token' => $token];
        if ($outletId !== null) {
            $sql .= ' AND outlet_id = :outlet_id';
            $params['outlet_id'] = $outletId;
        }
        $query = $this->pdo->prepare($sql . $suffix);
        $query->execute($params);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function lockOrderByPickupToken(string $token, ?int $outletId = null): ?array
    {
        if (!preg_match('/^G-[0-9]{3,}$/D', $token)) {
            throw new InvalidArgumentException('Enter a valid pickup token.');
        }
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sql = 'SELECT order_id, outlet_id, status, total_paise FROM orders '
            . 'WHERE pickup_token = :token AND pickup_business_date = :business_date';
        $params = ['token' => $token, 'business_date' => $this->businessDate()];
        if ($outletId !== null) {
            $sql .= ' AND outlet_id = :outlet_id';
            $params['outlet_id'] = $outletId;
        }
        $query = $this->pdo->prepare($sql . $suffix);
        $query->execute($params);
        $rows = $query->fetchAll();
        if ($rows === []) {
            $fallbackSql = "SELECT order_id, outlet_id, status, total_paise FROM orders "
                . "WHERE pickup_token = :token AND status IN ('draft', 'awaiting_payment', 'paid', 'preparing', 'ready')";
            $fallbackParams = ['token' => $token];
            if ($outletId !== null) {
                $fallbackSql .= ' AND outlet_id = :outlet_id';
                $fallbackParams['outlet_id'] = $outletId;
            }
            $fallback = $this->pdo->prepare($fallbackSql . $suffix);
            $fallback->execute($fallbackParams);
            $rows = $fallback->fetchAll();
        }
        if (count($rows) !== 1) {
            return null;
        }
        return $rows[0];
    }

    private function outletId(string $outletCode): int
    {
        $query = $this->pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :outlet_code AND active = 1');
        $query->execute(['outlet_code' => $outletCode]);
        $value = $query->fetchColumn();
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('The selected outlet is not available.');
        }
        $outletId = (int) $value;
        if ($outletId < 1) {
            throw new RuntimeException('The selected outlet is not available.');
        }
        return $outletId;
    }

    private function businessDate(): string
    {
        $timezoneName = Environment::get('APP_TIMEZONE', 'Asia/Kolkata') ?? 'Asia/Kolkata';
        try {
            $timezone = new DateTimeZone($timezoneName);
            $now = $this->businessClock === null
                ? new DateTimeImmutable('now', $timezone)
                : ($this->businessClock)();
        } catch (Throwable) {
            throw new RuntimeException('The configured business timezone is unavailable.');
        }
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('The configured business clock returned an invalid value.');
        }
        return $now->setTimezone($timezone)->format('Y-m-d');
    }

    private function allocatePickupNumber(int $outletId, string $businessDate): int
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $outletLock = $this->pdo->prepare(
            'SELECT outlet_id FROM outlets WHERE outlet_id = :outlet_id AND active = 1'
            . ($driver === 'mysql' ? ' FOR UPDATE' : '')
        );
        $outletLock->execute(['outlet_id' => $outletId]);
        if ($outletLock->fetchColumn() === false) {
            throw new RuntimeException('The selected outlet is not available.');
        }

        $seed = $driver === 'mysql'
            ? 'INSERT INTO pickup_token_counters (outlet_id, business_date, next_number) VALUES (:outlet_id, :business_date, 101) '
                . 'ON DUPLICATE KEY UPDATE next_number = next_number'
            : 'INSERT INTO pickup_token_counters (outlet_id, business_date, next_number) VALUES (:outlet_id, :business_date, 101) '
                . 'ON CONFLICT(outlet_id, business_date) DO NOTHING';
        $insert = $this->pdo->prepare($seed);
        $insert->execute(['outlet_id' => $outletId, 'business_date' => $businessDate]);

        $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $select = $this->pdo->prepare(
            'SELECT next_number FROM pickup_token_counters WHERE outlet_id = :outlet_id AND business_date = :business_date' . $suffix
        );
        $update = $this->pdo->prepare(
            'UPDATE pickup_token_counters SET next_number = :next_number '
            . 'WHERE outlet_id = :outlet_id AND business_date = :business_date AND next_number = :current_number'
        );
        $activeLookup = $this->pdo->prepare(
            "SELECT order_id FROM orders WHERE outlet_id = :outlet_id AND pickup_token = :pickup_token "
            . "AND status IN ('draft', 'awaiting_payment', 'paid', 'preparing', 'ready') "
            . (ShopOperations::enabled()?'AND pickup_business_date = :active_business_date ':'') . 'LIMIT 1'
            . ($driver === 'mysql' ? ' FOR UPDATE' : '')
        );
        for ($attempt = 0; $attempt < 100_000; $attempt++) {
            $select->execute(['outlet_id' => $outletId, 'business_date' => $businessDate]);
            $number = $select->fetchColumn();
            if (!is_string($number) && !is_int($number)) {
                throw new RuntimeException('A pickup token number could not be reserved.');
            }
            $number = (int) $number;
            if ($number < 101) {
                throw new RuntimeException('The pickup token counter contains an invalid value.');
            }
            $update->execute([
                'next_number' => $number + 1,
                'outlet_id' => $outletId,
                'business_date' => $businessDate,
                'current_number' => $number,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The pickup token counter changed during allocation.');
            }
            $pickupToken = 'G-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $activeParams=['outlet_id'=>$outletId,'pickup_token'=>$pickupToken];if(ShopOperations::enabled())$activeParams['active_business_date']=$businessDate;
            $activeLookup->execute($activeParams);
            if ($activeLookup->fetchColumn() === false) {
                return $number;
            }
        }
        throw new RuntimeException('No unused pickup token is available for this outlet.');
    }

    private function orderSelect(): string
    {
        return 'SELECT o.order_id, o.public_id, o.token, o.pickup_token, o.pickup_business_date, '
            . 'o.order_type, o.status, o.total_paise, o.outlet_code_snapshot, o.created_at FROM orders o';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Order
    {
        $itemsQuery = $this->pdo->prepare(
            'SELECT product_sku_snapshot, variant_code_snapshot, product_name_snapshot, variant_label_snapshot, '
            . 'unit_snapshot, quantity, unit_price_paise FROM order_items WHERE order_id = :id ORDER BY order_item_id'
        );
        $itemsQuery->execute(['id' => (int) $row['order_id']]);
        $items = [];
        foreach ($itemsQuery->fetchAll() as $item) {
            $unit = QuantityUnit::tryFrom((string) $item['unit_snapshot']);
            if ($unit === null) {
                throw new RuntimeException('An order item contains an unsupported quantity unit.');
            }
            $items[] = new OrderItem(
                (string) $item['product_sku_snapshot'],
                (string) $item['variant_code_snapshot'],
                (string) $item['product_name_snapshot'],
                (string) $item['variant_label_snapshot'],
                $unit,
                (int) $item['quantity'],
                Money::fromPaise((int) $item['unit_price_paise']),
            );
        }
        $historyQuery = $this->pdo->prepare('SELECT status FROM order_status_history WHERE order_id = :id ORDER BY history_id');
        $historyQuery->execute(['id' => (int) $row['order_id']]);
        $history = array_map('strval', $historyQuery->fetchAll(PDO::FETCH_COLUMN));
        $type = OrderType::tryFrom((string) $row['order_type']);
        $status = OrderStatus::tryFrom((string) $row['status']);
        if ($type === null || $status === null) {
            throw new RuntimeException('An order contains an unsupported type or status.');
        }
        return new Order(
            (string) $row['public_id'],
            (string) $row['outlet_code_snapshot'],
            (string) $row['token'],
            $type,
            $status,
            $items,
            Money::fromPaise((int) $row['total_paise']),
            (string) $row['created_at'],
            $history,
            $row['pickup_token'] === null ? null : (string) $row['pickup_token'],
        );
    }

    private function writeHistory(int $orderId, OrderStatus $status, ?string $changedBy = null): void
    {
        $history = $this->pdo->prepare(
            'INSERT INTO order_status_history (order_id, status, changed_by) VALUES (:order_id, :status, :changed_by)'
        );
        $history->execute(['order_id' => $orderId, 'status' => $status->value, 'changed_by' => $changedBy]);
    }

    /** @param array<string, mixed> $intent */
    private function findCheckoutIntentOrder(array $intent): ?Order
    {
        $query = $this->pdo->prepare(
            'SELECT order_id, checkout_session_binding_hash, checkout_cart_fingerprint, outlet_code_snapshot, order_type '
            . 'FROM orders WHERE checkout_idempotency_key_hash = :key_hash'
        );
        $query->execute(['key_hash' => $intent['keyHash']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $matches = is_string($row['checkout_session_binding_hash'])
            && is_string($row['checkout_cart_fingerprint'])
            && hash_equals($intent['sessionBindingHash'], $row['checkout_session_binding_hash'])
            && hash_equals($intent['cartFingerprint'], $row['checkout_cart_fingerprint'])
            && hash_equals((string) $intent['outletCode'], (string) $row['outlet_code_snapshot'])
            && hash_equals((string) $intent['orderType'], (string) $row['order_type']);
        if (!$matches) {
            throw new DomainException('This checkout attempt cannot be used for this session or cart. Reload your cart.');
        }

        $order = $this->findById((int) $row['order_id']);
        return $order ?? throw new RuntimeException('The committed checkout order could not be reloaded.');
    }

    private function isUniqueCheckoutIntentCollision(PDOException $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'uq_orders_checkout_idempotency_hash')
            || str_contains($message, 'orders.checkout_idempotency_key_hash');
    }

    private function isUniqueOrderCollision(PDOException $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'uq_orders_token')
            || str_contains($message, 'uq_orders_public_id')
            || str_contains($message, 'orders.token')
            || str_contains($message, 'orders.public_id');
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
