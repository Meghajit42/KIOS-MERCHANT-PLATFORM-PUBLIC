<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PreparationStation: string
{
    case Sweets = 'SWEETS';
    case Chaat = 'CHAAT';
    case SouthIndian = 'SOUTH_INDIAN';
    case NorthIndian = 'NORTH_INDIAN';
    case Momo = 'MOMO';
    case Tandoor = 'TANDOOR';
    case Gravy = 'GRAVY';
    case Noodles = 'NOODLES';
    case Beverage = 'BEVERAGE';
    case GeneralKitchen = 'GENERAL_KITCHEN';
}
