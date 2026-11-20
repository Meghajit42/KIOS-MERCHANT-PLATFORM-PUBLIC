<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/PersistenceTest.php';

use Kios\Domain\OrderType;
use Kios\Domain\PaymentMethod;
use Kios\Infrastructure\OrderRepository;
use Kios\Infrastructure\PreparationRoutingService;

/** @return array{int, string} */
function kiosPreparationOrderId(PDO $pdo, string $token): array
{
    $query = $pdo->prepare('SELECT order_id, outlet_id FROM orders WHERE token = :token');
    $query->execute(['token' => $token]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('The test order was not found.');
    }
    return [(int) $row['order_id'], (string) $row['outlet_id']];
}

/** Mark the order paid only for a focused service-level routing test. */
function kiosPreparationMarkPaid(PDO $pdo, int $orderId): void
{
    $update = $pdo->prepare('UPDATE orders SET status = :status WHERE order_id = :order_id');
    $update->execute(['status' => 'paid', 'order_id' => $orderId]);
}

return [
    'routing requires an active transaction and rejects awaiting payment' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        [$orderId, $outletId] = kiosPreparationOrderId($pdo, $order->token);
        $service = new PreparationRoutingService($pdo);

        try {
            $service->routePaidOrder($orderId, (int) $outletId);
        } catch (LogicException) {
            // A caller-owned transaction is required.
        }

        $pdo->beginTransaction();
        try {
            $service->routePaidOrder($orderId, (int) $outletId);
        } catch (DomainException) {
            $pdo->rollBack();
            if ((int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 0) {
                throw new RuntimeException('an unpaid order created preparation tasks');
            }
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('an awaiting-payment order was routed');
    },
    'paid mixed order splits once and preserves each item to its configured station' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        [$orderId, $outletId] = kiosPreparationOrderId($pdo, $order->token);
        $service = new PreparationRoutingService($pdo);

        $pdo->beginTransaction();
        kiosPreparationMarkPaid($pdo, $orderId);
        $created = $service->routePaidOrder($orderId, (int) $outletId);
        $pdo->commit();
        if ($created !== 2
            || (int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 2
            || (int) $pdo->query('SELECT COUNT(*) FROM preparation_task_items')->fetchColumn() !== 2) {
            throw new RuntimeException('mixed order did not create exactly two station tasks and item links');
        }

        $assignments = $pdo->query(
            'SELECT oi.product_sku_snapshot, ps.code FROM preparation_task_items pti '
            . 'JOIN preparation_tasks pt ON pt.outlet_id = pti.outlet_id AND pt.order_id = pti.order_id '
            . 'AND pt.preparation_task_id = pti.preparation_task_id '
            . 'JOIN preparation_stations ps ON ps.outlet_id = pt.outlet_id AND ps.station_id = pt.station_id '
            . 'JOIN order_items oi ON oi.outlet_id = pti.outlet_id AND oi.order_id = pti.order_id '
            . 'AND oi.order_item_id = pti.order_item_id ORDER BY oi.product_sku_snapshot'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($assignments !== ['kaju-barfi' => 'SWEETS', 'papdi-chaat' => 'CHAAT']) {
            throw new RuntimeException('an order item was linked to the wrong station');
        }

        $pdo->beginTransaction();
        $reused = $service->routePaidOrder($orderId, (int) $outletId);
        $pdo->commit();
        if ($reused !== 2
            || (int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 2
            || (int) $pdo->query('SELECT COUNT(*) FROM preparation_task_items')->fetchColumn() !== 2) {
            throw new RuntimeException('repeated routing created duplicate preparation work');
        }
    },
    'station edits after checkout do not reroute the accepted order' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $before = $pdo->query('SELECT oi.product_sku_snapshot, ps.code FROM order_items oi '
            . 'JOIN preparation_stations ps ON ps.station_id = oi.station_id_snapshot ORDER BY oi.product_sku_snapshot')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pdo->exec("UPDATE products SET station_id = 5 WHERE sku = 'papdi-chaat'");
        $repository->confirmPaymentByInternalToken($order->token, PaymentMethod::Cash);
        $actual = $pdo->query('SELECT oi.product_sku_snapshot, ps.code FROM preparation_task_items pti '
            . 'JOIN preparation_tasks pt ON pt.preparation_task_id = pti.preparation_task_id '
            . 'JOIN preparation_stations ps ON ps.station_id = pt.station_id '
            . 'JOIN order_items oi ON oi.order_item_id = pti.order_item_id ORDER BY oi.product_sku_snapshot')->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($before !== ['kaju-barfi' => 'SWEETS', 'papdi-chaat' => 'CHAAT']
            || $actual !== ['kaju-barfi' => 'SWEETS', 'papdi-chaat' => 'CHAAT']) {
            throw new RuntimeException('a later menu station edit changed routing for an existing order');
        }
    },
    'routing rejects an outlet mismatch without creating work' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        [$orderId] = kiosPreparationOrderId($pdo, $order->token);
        $service = new PreparationRoutingService($pdo);
        $pdo->beginTransaction();
        kiosPreparationMarkPaid($pdo, $orderId);
        try {
            $service->routePaidOrder($orderId, 2);
        } catch (DomainException) {
            $pdo->rollBack();
            if ((int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 0) {
                throw new RuntimeException('outlet mismatch left preparation tasks');
            }
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('routing accepted the wrong outlet');
    },
    'staff token lookup and payment confirmation remain outlet scoped' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $otherOrder = $repository->createFromCart(['other-item' => ['other-portion' => 1]], OrderType::Takeaway);
        if ($repository->findByInternalToken($otherOrder->token, 1) !== null
            || $repository->findByInternalToken($otherOrder->token, 2) === null) {
            throw new RuntimeException('token lookup crossed the requested outlet boundary');
        }
        try {
            $repository->confirmPaymentByInternalToken($otherOrder->token, PaymentMethod::Cash, 'local-cashier', 1);
        } catch (InvalidArgumentException) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn() !== 0
                || $pdo->query('SELECT status FROM orders WHERE token = ' . $pdo->quote($otherOrder->token))->fetchColumn() !== 'awaiting_payment') {
                throw new RuntimeException('cross-outlet payment attempt left partial state');
            }
            return;
        }
        throw new RuntimeException('cashier confirmed an order in another outlet');
    },
    'inactive station rejects payment and rolls back all routing rows' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $pdo->exec("UPDATE preparation_stations SET active = 0 WHERE outlet_id = 1 AND code = 'CHAAT'");
        try {
            $repository->confirmPaymentByInternalToken($order->token, PaymentMethod::Cash);
        } catch (DomainException) {
            if ($pdo->query('SELECT status FROM orders WHERE token = ' . $pdo->quote($order->token))->fetchColumn() !== 'awaiting_payment'
                || (int) $pdo->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 0) {
                throw new RuntimeException('failed routing left partial payment or task state');
            }
            return;
        }
        throw new RuntimeException('payment succeeded with an inactive required station');
    },
    'failed task-item insert rolls back payment and every task' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $pdo->exec("CREATE TRIGGER fail_preparation_link BEFORE INSERT ON preparation_task_items BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try {
            $repository->confirmPaymentByInternalToken($order->token, PaymentMethod::Card);
        } catch (PDOException) {
            if ($pdo->query('SELECT status FROM orders WHERE token = ' . $pdo->quote($order->token))->fetchColumn() !== 'awaiting_payment'
                || (int) $pdo->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM preparation_task_items')->fetchColumn() !== 0) {
                throw new RuntimeException('routing failure did not roll back the full payment transaction');
            }
            return;
        }
        throw new RuntimeException('fixture did not interrupt preparation item linking');
    },
];
