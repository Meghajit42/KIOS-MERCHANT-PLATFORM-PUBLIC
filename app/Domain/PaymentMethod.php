<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case Card = 'card';
    case Other = 'other';
}
