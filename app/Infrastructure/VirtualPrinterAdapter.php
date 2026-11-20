<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\PrintErrorCategory;
use Kios\Domain\PrintJob;
use RuntimeException;

final class VirtualPrinterAdapter implements PrintAdapter
{
    public function __construct(private readonly string $spoolDirectory)
    {
    }

    public function print(PrintJob $job, string $bytes): string
    {
        if ($this->spoolDirectory === '' || is_link($this->spoolDirectory)) {
            throw new PrintAdapterFailure(PrintErrorCategory::WriteFailed, 'The virtual spool location is unavailable.', true);
        }
        if (!is_dir($this->spoolDirectory) && !@mkdir($this->spoolDirectory, 0770, true) && !is_dir($this->spoolDirectory)) {
            throw new PrintAdapterFailure(PrintErrorCategory::WriteFailed, 'The virtual spool could not be created.', true);
        }

        $filename = sprintf('print-job-%010d-attempt-%03d.json', $job->id, $job->attemptCount);
        $destination = rtrim($this->spoolDirectory, '\\/') . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($destination) || is_link($destination)) {
            throw new PrintAdapterFailure(PrintErrorCategory::WriteFailed, 'The virtual spool output already exists.', false);
        }

        $record = [
            'jobId' => $job->id,
            'outletId' => $job->outletId,
            'jobType' => $job->type->value,
            'token' => $job->token,
            'stationCode' => $job->stationCode,
            'stationName' => $job->stationName,
            'printer' => $job->printerName,
            'paperWidthMm' => $job->paperWidthMm,
            'attempt' => $job->attemptCount,
            'text' => $job->payloadText,
            'bytesHex' => strtoupper(bin2hex($bytes)),
        ];
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $temporary = tempnam($this->spoolDirectory, '.print-');
            if (!is_string($temporary)) {
                throw new RuntimeException('Temporary spool file could not be allocated.');
            }
            $written = file_put_contents($temporary, $json . PHP_EOL, LOCK_EX);
            if ($written !== strlen($json . PHP_EOL) || !@rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('Atomic spool write failed.');
            }
        } catch (\Throwable) {
            if (isset($temporary) && is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new PrintAdapterFailure(PrintErrorCategory::WriteFailed, 'The virtual spool write failed.', true);
        }

        return $filename;
    }
}
