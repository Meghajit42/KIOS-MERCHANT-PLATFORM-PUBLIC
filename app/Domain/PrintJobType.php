<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrintJobType: string
{
    case CashierReceipt = 'CASHIER_RECEIPT';
    case StationKot = 'STATION_KOT';
}
