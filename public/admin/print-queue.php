<?php
declare(strict_types=1);

use Kios\Application\PrintQueueView;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\PrintGateway;
use Kios\Infrastructure\PrintJobService;

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
if (!PrintGateway::enabled()) {
    http_response_code(404);
    exit('Print gateway is disabled.');
}

$jobs = [];
try {
    $pdo = Database::connect();
    $outletQuery = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = ? AND active = 1');
    $outletQuery->execute([KIOS_ADMIN_OUTLET]);
    $outlet = (int) $outletQuery->fetchColumn();
    $printing = new PrintJobService($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        adminRequireCsrf();
        $id = adminPost('print_job_id');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new DomainException('Select a valid ticket.');
        }
        if (adminPost('action') === 'cancel-print-job') {
            if (!(new PrintGateway($pdo))->cancelUnclaimed($outlet, (int) $id)) {
                throw new DomainException('Only an unclaimed gateway ticket can be cancelled.');
            }
        } elseif (adminPost('action') === 'reprint-print-job' && adminPost('inspected') === '1') {
            $printing->reprint((int) $id, $outlet, 'owner-admin', adminPost('reason'), adminPost('request_id'));
        } else {
            throw new DomainException('Check for an earlier paper copy before requesting a reprint.');
        }
        adminFlash('Print action recorded.');
        header('Location: /admin/print-queue.php', true, 303);
        exit;
    }
    $jobs = array_values(array_filter($printing->listForOutlet($outlet), static fn(array $job): bool =>
        $job['adapter_type_snapshot'] === 'gateway' || $job['last_error_category'] === 'no_printer_configured'
    ));
} catch (Throwable $error) {
    http_response_code(400);
    adminFlash(adminFail($error));
}
adminStart('Print queue');
echo '<p>Check the printer before reprinting any ticket with uncertain paper output.</p>';
echo PrintQueueView::render($jobs, adminToken(), true);
adminEnd();
