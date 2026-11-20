<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrinterRole: string
{
    case CashierReceipt = 'cashier_receipt';
    case StationKot = 'station_kot';
}
