<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrinterAdapterType: string
{
    case Virtual = 'virtual';
    case WindowsSpooler = 'windows_spooler';
    case Gateway = 'gateway';
}
