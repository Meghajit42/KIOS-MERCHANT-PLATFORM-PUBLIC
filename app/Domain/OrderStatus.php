<?php
declare(strict_types=1);

namespace Kios\Domain;

enum OrderStatus: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Collected = 'collected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
