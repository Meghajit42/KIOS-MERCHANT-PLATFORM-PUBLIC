<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\PrintJob;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\PrintAdapter;
use Kios\Infrastructure\PrintJobService;
use Kios\Infrastructure\VirtualPrinterAdapter;

final class DelayedK5MariaDbVirtualAdapter implements PrintAdapter
{
    private VirtualPrinterAdapter $inner;

    public function __construct()
    {
        $this->inner = new VirtualPrinterAdapter(dirname(__DIR__) . '/storage/runtime/print-spool');
    }

    public function print(PrintJob $job, string $bytes): string
    {
        usleep(500_000);
        return $this->inner->print($job, $bytes);
    }
}

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
    $status = (new PrintJobService($pdo, new DelayedK5MariaDbVirtualAdapter()))->dispatch((int) $jobInput, (int) $outletId);
    echo $status->value . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'K5 local claim worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}
