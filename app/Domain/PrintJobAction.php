<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrintJobAction: string
{
    case Print = 'PRINT';
    case Retry = 'RETRY';
    case Reprint = 'REPRINT';
}
