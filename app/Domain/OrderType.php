<?php
declare(strict_types=1);

namespace Kios\Domain;

enum OrderType: string
{
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';
}
