<?php
declare(strict_types=1);

namespace Kios\Domain;

enum QuantityUnit: string
{
    case Piece = 'piece';
    case Gram = 'gram';
    case Plate = 'plate';
    case Portion = 'portion';
    case Count = 'count';
}
