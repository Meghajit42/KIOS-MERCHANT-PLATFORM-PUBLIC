<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

interface WindowsSpoolerSender
{
    /** @throws PrintAdapterFailure */
    public function send(string $printerName, string $bytes): void;
}
