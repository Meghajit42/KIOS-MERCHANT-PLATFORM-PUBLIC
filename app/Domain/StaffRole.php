<?php
declare(strict_types=1);

namespace Kios\Domain;

enum StaffRole: string
{
    case Cashier = 'cashier';
    case GroundFloor = 'ground_floor';
    case FirstFloorKitchen = 'first_floor_kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Cashier => 'Cashier',
            self::GroundFloor => 'Ground Floor',
            self::FirstFloorKitchen => 'First Floor Kitchen',
        };
    }

    public function department(): ?OperationalDepartment
    {
        return match ($this) {
            self::Cashier => null,
            self::GroundFloor => OperationalDepartment::GroundFloor,
            self::FirstFloorKitchen => OperationalDepartment::FirstFloorKitchen,
        };
    }

    public function passwordEnvironmentKey(): string
    {
        return match ($this) {
            self::Cashier => 'KIOS_CASHIER_PASSWORD',
            self::GroundFloor => 'KIOS_GROUND_FLOOR_PASSWORD',
            self::FirstFloorKitchen => 'KIOS_FIRST_FLOOR_KITCHEN_PASSWORD',
        };
    }

    public function allowsAction(string $action): bool
    {
        return match ($this) {
            self::Cashier => in_array($action, [
                'confirm-payment', 'cancel-order', 'retry-print-job', 'reprint-print-job',
            ], true),
            self::GroundFloor => in_array($action, ['advance-station-task', 'collect-order'], true),
            self::FirstFloorKitchen => $action === 'advance-station-task',
        };
    }
}
