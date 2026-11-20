<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\PrintJob;

interface PrintAdapter
{
    /** Returns a safe adapter reference, such as a spool filename. */
    public function print(PrintJob $job, string $bytes): string;
}
