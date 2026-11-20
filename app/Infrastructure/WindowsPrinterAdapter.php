<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\PrintErrorCategory;
use Kios\Domain\PrintJob;

final class WindowsPrinterAdapter implements PrintAdapter
{
    public function __construct(private readonly ?WindowsSpoolerSender $sender = null)
    {
    }

    public function print(PrintJob $job, string $bytes): string
    {
        if ($job->configurationRef === null || trim($job->configurationRef) === '' || strlen($job->configurationRef) > 160
            || preg_match('/[\x00-\x1F\x7F]/', $job->configurationRef)) {
            throw new PrintAdapterFailure(PrintErrorCategory::PrinterNotFound, 'The configured Windows printer name is invalid.');
        }
        $sender = $this->sender ?? new WindowsRawSpoolerSender();
        $sender->send($job->configurationRef, $bytes);
        return 'sent-to-configured-windows-printer';
    }
}
