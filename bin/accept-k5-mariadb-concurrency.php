<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;

try {
    $pdo = Database::connect();
    $outletQuery = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $outletQuery->execute(['code' => 'demo-outlet']);
    $outletId = $outletQuery->fetchColumn();
    if ($outletId === false) {
        throw new RuntimeException('The local demo outlet is unavailable.');
    }
    $outletId = (int) $outletId;
    $sourceQuery = $pdo->prepare(
        "SELECT p.* FROM print_jobs p JOIN orders o ON o.outlet_id = p.outlet_id AND o.order_id = p.order_id "
        . "JOIN payment_confirmations pc ON pc.order_id = o.order_id "
        . "WHERE p.outlet_id = :outlet AND pc.confirmed_by = 'k5-synthetic-acceptance' "
        . "AND p.job_type = 'STATION_KOT' AND p.reprint_of_job_id IS NULL AND p.status = 'printed' "
        . 'ORDER BY p.print_job_id DESC LIMIT 1'
    );
    $sourceQuery->execute(['outlet' => $outletId]);
    $source = $sourceQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($source) || $source['adapter_type_snapshot'] !== 'virtual' || $source['printer_id'] === null) {
        throw new RuntimeException('A printed synthetic virtual KOT was not found for the concurrency check.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO print_jobs (outlet_id, order_id, preparation_task_id, station_id, printer_id, job_type, status, '
        . 'idempotency_key, token_snapshot, station_code_snapshot, station_name_snapshot, printer_name_snapshot, '
        . 'adapter_type_snapshot, configuration_ref_snapshot, paper_width_mm, cut_after_print, payload_text) '
        . "VALUES (:outlet, :order, :task, :station, :printer, 'STATION_KOT', 'queued', :idempotency, :token, :station_code, "
        . ':station_name, :printer_name, :adapter, :configuration_ref, :paper_width, :cut, :payload)'
    );
    $idempotency = 'k5-mariadb-claim-' . bin2hex(random_bytes(12));
    $insert->execute([
        'outlet' => $outletId,
        'order' => (int) $source['order_id'],
        'task' => (int) $source['preparation_task_id'],
        'station' => (int) $source['station_id'],
        'printer' => (int) $source['printer_id'],
        'idempotency' => $idempotency,
        'token' => (string) $source['token_snapshot'],
        'station_code' => (string) $source['station_code_snapshot'],
        'station_name' => (string) $source['station_name_snapshot'],
        'printer_name' => (string) $source['printer_name_snapshot'],
        'adapter' => (string) $source['adapter_type_snapshot'],
        'configuration_ref' => $source['configuration_ref_snapshot'],
        'paper_width' => (int) $source['paper_width_mm'],
        'cut' => (int) $source['cut_after_print'],
        'payload' => (string) $source['payload_text'],
    ]);
    $jobId = (int) $pdo->lastInsertId();

    $worker = __DIR__ . '/k5-mariadb-print-worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $process = proc_open([PHP_BINARY, $worker, (string) $jobId], $descriptors, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start two local MariaDB claim workers.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout) || trim($stdout) === '' || !is_string($stderr)) {
            throw new RuntimeException('A MariaDB claim worker failed: ' . trim((string) $stderr));
        }
    }

    $check = Database::connect();
    $jobQuery = $check->prepare('SELECT status, attempt_count FROM print_jobs WHERE outlet_id = :outlet AND print_job_id = :job');
    $jobQuery->execute(['outlet' => $outletId, 'job' => $jobId]);
    $job = $jobQuery->fetch(PDO::FETCH_ASSOC);
    $attemptQuery = $check->prepare('SELECT COUNT(*) FROM print_job_attempts WHERE outlet_id = :outlet AND print_job_id = :job');
    $attemptQuery->execute(['outlet' => $outletId, 'job' => $jobId]);
    $spoolFile = dirname(__DIR__) . '/storage/runtime/print-spool/' . sprintf('print-job-%010d-attempt-001.json', $jobId);
    if (!is_array($job) || $job['status'] !== 'printed' || (int) $job['attempt_count'] !== 1
        || (int) $attemptQuery->fetchColumn() !== 1 || !is_file($spoolFile)) {
        throw new RuntimeException('Two MariaDB processes did not produce exactly one claim, attempt, and virtual spool output.');
    }
    echo "MariaDB two-process claim: one attempt and one virtual output; job {$jobId} printed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'K5 MariaDB concurrency acceptance failed: ' . $error->getMessage() . "\n");
    exit(1);
}
