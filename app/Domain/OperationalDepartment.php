<?php
declare(strict_types=1);

namespace Kios\Domain;

enum OperationalDepartment: string
{
    case GroundFloor = 'GROUND_FLOOR';
    case FirstFloorKitchen = 'FIRST_FLOOR_KITCHEN';

    public function label(): string
    {
        return match ($this) {
            self::GroundFloor => 'Ground Floor',
            self::FirstFloorKitchen => 'First Floor Kitchen',
        };
    }
}
