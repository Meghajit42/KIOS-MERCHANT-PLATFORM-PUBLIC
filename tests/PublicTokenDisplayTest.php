<?php
declare(strict_types=1);

use Kios\Application\PublicTokenDisplayController;
use Kios\Application\PublicTokenDisplayView;
use Kios\Domain\OrderType;
use Kios\Domain\PaymentMethod;
use Kios\Infrastructure\PublicTokenDisplayService;

function kiosDisplaySetStatus(\PDO $pdo, string $token, string $status): void
{
    $query = $pdo->prepare('UPDATE orders SET status = :status WHERE token = :token');
    $query->execute(['status' => $status, 'token' => $token]);
}

function kiosDisplayMarkReady(\PDO $pdo, string $token, string $updatedAt): void
{
    $tasks = $pdo->prepare("UPDATE preparation_tasks SET status = 'ready' WHERE order_id = (SELECT order_id FROM orders WHERE token = :token)");
    $tasks->execute(['token' => $token]);
    $order = $pdo->prepare("UPDATE orders SET status = 'ready', updated_at = :updated_at WHERE token = :token");
    $order->execute(['updated_at' => $updatedAt, 'token' => $token]);
}

function kiosDisplayFindToken(array $snapshot, string $token): ?array
{
    foreach ($snapshot['tokens'] as $row) {
        if ($row['token'] === $token) {
            return $row;
        }
    }
    return null;
}

return [
    'public display includes routed preparation with only public fields' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($order->token, PaymentMethod::Cash);

        $snapshot = (new PublicTokenDisplayService($pdo))->snapshot();
        $row = kiosDisplayFindToken($snapshot, $order->publicToken());
        if ($snapshot['outlet'] !== 'Synthetic demo outlet' || $row === null || $row['publicStatus'] !== 'PREPARING') {
            throw new \RuntimeException('a paid and routed order was not shown as preparing');
        }
        if (array_keys($row) !== ['token', 'publicStatus', 'outlet', 'updatedAt']) {
            throw new \RuntimeException('the public token row contains unexpected fields');
        }
    },
    'ready display waits for every preparation task to finish' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($order->token, PaymentMethod::Cash);

        $firstTask = $pdo->prepare('SELECT preparation_task_id FROM preparation_tasks WHERE order_id = (SELECT order_id FROM orders WHERE token = :token) ORDER BY preparation_task_id LIMIT 1');
        $firstTask->execute(['token' => $order->token]);
        $taskId = (int) $firstTask->fetchColumn();
        $markOneReady = $pdo->prepare("UPDATE preparation_tasks SET status = 'ready' WHERE preparation_task_id = :task_id");
        $markOneReady->execute(['task_id' => $taskId]);

        $service = new PublicTokenDisplayService($pdo);
        $row = kiosDisplayFindToken($service->snapshot(), $order->publicToken());
        if ($row === null || $row['publicStatus'] !== 'PREPARING') {
            throw new \RuntimeException('an order with an unfinished task was not kept in preparing');
        }

        kiosDisplayMarkReady($pdo, $order->token, '2026-10-31 10:00:00');
        $row = kiosDisplayFindToken($service->snapshot(), $order->publicToken());
        if ($row === null || $row['publicStatus'] !== 'READY' || $row['updatedAt'] !== '2026-10-31 10:00:00') {
            throw new \RuntimeException('an order was not shown ready after every task completed');
        }
    },
    'display excludes unpaid unrouted paid and terminal order states' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        foreach (['awaiting_payment', 'paid', 'collected', 'cancelled', 'expired', 'draft'] as $status) {
            $order = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
            kiosDisplaySetStatus($pdo, $order->token, $status);
        }

        $snapshot = (new PublicTokenDisplayService($pdo))->snapshot();
        if ($snapshot['tokens'] !== []) {
            throw new \RuntimeException('an unpaid, unrouted, or terminal order appeared on the public display');
        }
    },
    'display uses oldest preparing then newest ready order and isolates outlet' => static function (): void {
        [$pdo, $repository] = kiosTestPersistence();
        $older = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($older->token, PaymentMethod::Cash);
        $newer = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($newer->token, PaymentMethod::Cash);
        $setCreated = $pdo->prepare('UPDATE orders SET created_at = :created_at WHERE token = :token');
        $setCreated->execute(['created_at' => '2026-10-31 08:00:00', 'token' => $older->token]);
        $setCreated->execute(['created_at' => '2026-10-31 09:00:00', 'token' => $newer->token]);

        $readyOlder = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($readyOlder->token, PaymentMethod::Cash);
        kiosDisplayMarkReady($pdo, $readyOlder->token, '2026-10-31 10:00:00');
        $readyNewer = $repository->createFromCart(kiosTestCart(), OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($readyNewer->token, PaymentMethod::Cash);
        kiosDisplayMarkReady($pdo, $readyNewer->token, '2026-10-31 11:00:00');

        $other = $repository->createFromCart(['other-item' => ['other-portion' => 1]], OrderType::Takeaway);
        $repository->confirmPaymentByInternalToken($other->token, PaymentMethod::Cash);
        $tokens = (new PublicTokenDisplayService($pdo))->snapshot()['tokens'];
        $visible = array_column($tokens, 'token');
        $expected = [$older->publicToken(), $newer->publicToken(), $readyNewer->publicToken(), $readyOlder->publicToken()];
        if (count($visible) !== 4 || $visible !== $expected) {
            throw new \RuntimeException('public tokens were not sorted predictably or leaked across outlets: ' . json_encode($visible));
        }
    },
    'refresh controller returns a safe JSON snapshot and rejects mutations' => static function (): void {
        [$pdo] = kiosTestPersistence();
        $controller = new PublicTokenDisplayController(static fn(): \PDO => $pdo);
        $response = $controller->respond('GET');
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || $response['headers']['Cache-Control'] !== 'no-store'
            || !str_starts_with($response['headers']['Content-Type'], 'application/json')
            || array_keys($payload) !== ['outlet', 'tokens'] || $payload['tokens'] !== []) {
            throw new \RuntimeException('the display refresh response is not a safe empty JSON snapshot');
        }

        $blocked = new PublicTokenDisplayController(static function (): \PDO {
            throw new \RuntimeException('the database connector should not run for a mutation request');
        });
        $post = $blocked->respond('POST');
        if ($post['status'] !== 405 || ($post['headers']['Allow'] ?? '') !== 'GET') {
            throw new \RuntimeException('the public display accepted a non-GET request');
        }

        $failed = (new PublicTokenDisplayController(static function (): \PDO {
            throw new \RuntimeException('private connection diagnostic');
        }))->respond('GET');
        if ($failed['status'] !== 503 || str_contains($failed['body'], 'private connection diagnostic')) {
            throw new \RuntimeException('a refresh failure leaked internal diagnostics');
        }
    },
    'display view escapes tokens and distinguishes empty from unavailable state' => static function (): void {
        $snapshot = ['outlet' => 'Synthetic demo outlet', 'tokens' => [[
            'token' => '<img src=x>',
            'publicStatus' => 'PREPARING',
            'outlet' => 'Synthetic demo outlet',
            'updatedAt' => '2026-10-31 10:00:00',
        ]]];
        $html = PublicTokenDisplayView::render($snapshot);
        if (!str_contains($html, '&lt;img src=x&gt;') || str_contains($html, '<img src=x>')
            || !str_contains($html, 'id="preparing-tokens"') || !str_contains($html, 'id="ready-tokens"')
            || str_contains($html, '<form')) {
            throw new \RuntimeException('the public view did not escape or isolate token-only display markup');
        }

        $empty = PublicTokenDisplayView::render(['outlet' => 'Synthetic demo outlet', 'tokens' => []]);
        $unavailable = PublicTokenDisplayView::render(['outlet' => 'Kios pickup board', 'tokens' => []], true);
        if (!str_contains($empty, 'No orders are waiting right now.') || !str_contains($empty, 'PREPARING')
            || !str_contains($empty, 'READY') || !str_contains($unavailable, 'Connection lost.')
            || !str_contains($empty, 'id="display-connection"')
            || !str_contains($unavailable, 'id="display-empty" class="display-empty" role="status" hidden')) {
            throw new \RuntimeException('empty and connection failure states were not distinguished');
        }
    },
    'live display polls quickly and hides stale announcements while offline' => static function (string $root): void {
        $script = (string) file_get_contents($root . '/public/assets/display.js');
        if (!str_contains($script, 'const pollIntervalMs = 1500;')
            || !str_contains($script, "credentials: 'omit'")
            || !str_contains($script, "cache: 'no-store'")
            || !str_contains($script, "connection.textContent = 'Offline · reconnecting'")
            || !str_contains($script, 'preparingList?.replaceChildren()')
            || !str_contains($script, 'readyList?.replaceChildren()')
            || !str_contains($script, 'lastSnapshot =')) {
            throw new \RuntimeException('the live board can retain stale READY state or lacks bounded polling');
        }
    },
];
