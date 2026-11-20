<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\PrintJobService;

try {
    $jobInput = $argv[1] ?? '';
    if (!is_string($jobInput) || !preg_match('/^[1-9][0-9]{0,17}$/D', $jobInput)) {
        throw new InvalidArgumentException('Pass one local print-job ID.');
    }
    $pdo = Database::connect();
    $outletQuery = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $outletQuery->execute(['code' => 'demo-outlet']);
    $outletId = $outletQuery->fetchColumn();
    if ($outletId === false) {
        throw new RuntimeException('The local demo outlet is unavailable.');
    }
    $jobId = (int) $jobInput;
    $precheck = $pdo->prepare(
        'SELECT job_type, status, attempt_count, last_error_category, reprint_of_job_id, reprint_reason '
        . 'FROM print_jobs WHERE outlet_id = :outlet AND print_job_id = :job'
    );
    $precheck->execute(['outlet' => (int) $outletId, 'job' => $jobId]);
    $candidate = $precheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($candidate) || $candidate['job_type'] !== 'STATION_KOT' || $candidate['status'] !== 'failed'
        || (int) $candidate['attempt_count'] !== 3 || $candidate['last_error_category'] !== 'write_failed'
        || $candidate['reprint_of_job_id'] === null
        || $candidate['reprint_reason'] !== 'Synthetic virtual printer failure and recovery test') {
        throw new RuntimeException('The supplied ID is not a pending K5 synthetic recovery job.');
    }
    $status = (new PrintJobService($pdo))->retry($jobId, (int) $outletId, 'k5-synthetic-acceptance');
    $query = $pdo->prepare('SELECT status, attempt_count, last_error_category, order_id FROM print_jobs WHERE outlet_id = :outlet AND print_job_id = :job');
    $query->execute(['outlet' => (int) $outletId, 'job' => $jobId]);
    $job = $query->fetch(PDO::FETCH_ASSOC);
    if ($status->value !== 'printed' || !is_array($job) || $job['status'] !== 'printed' || (int) $job['attempt_count'] !== 4
        || $pdo->query('SELECT status FROM orders WHERE order_id = ' . (int) $job['order_id'])->fetchColumn() !== 'preparing'
        || (int) $pdo->query('SELECT COUNT(*) FROM preparation_tasks WHERE outlet_id = ' . (int) $outletId . ' AND order_id = ' . (int) $job['order_id'])->fetchColumn() !== 3) {
        throw new RuntimeException('The persisted recovery job did not print or the order/tasks changed.');
    }
    echo "Restarted-process recovery: printed after 3 failed attempts + 1 staff retry.\n";
    echo "Order remains preparing; all 3 station tasks remain persisted.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'K5 local retry acceptance failed: ' . $error->getMessage() . "\nThe persisted job and order were not deleted.\n");
    exit(1);
}
