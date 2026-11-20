<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\PrintJob;
use Kios\Infrastructure\PrintAdapter;
use Kios\Infrastructure\PrintJobService;
use Kios\Infrastructure\VirtualPrinterAdapter;

final class DelayedVirtualPrintAdapter implements PrintAdapter
{
    private VirtualPrinterAdapter $inner;

    public function __construct(string $spool)
    {
        $this->inner = new VirtualPrinterAdapter($spool);
    }

    public function print(PrintJob $job, string $bytes): string
    {
        usleep(350_000);
        return $this->inner->print($job, $bytes);
    }
}

try {
    $databasePath = $argv[1] ?? '';
    $jobId = (int) ($argv[2] ?? 0);
    $outletId = (int) ($argv[3] ?? 0);
    $spool = $argv[4] ?? '';
    if ($databasePath === '' || $jobId < 1 || $outletId < 1 || $spool === '') {
        throw new RuntimeException('Worker arguments are invalid.');
    }
    $pdo = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $status = (new PrintJobService($pdo, new DelayedVirtualPrintAdapter($spool)))->dispatch($jobId, $outletId);
    echo $status->value . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'print worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}
