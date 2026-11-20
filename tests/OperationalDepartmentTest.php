<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\OperationalDepartment;
use Kios\Domain\ProductRoutingStatus;
use Kios\Domain\StaffRole;

return [
    'operational department identifiers and labels are closed and explicit' => static function (): void {
        if (OperationalDepartment::GroundFloor->value !== 'GROUND_FLOOR'
            || OperationalDepartment::FirstFloorKitchen->value !== 'FIRST_FLOOR_KITCHEN'
            || OperationalDepartment::tryFrom('UNKNOWN') !== null
            || OperationalDepartment::GroundFloor->label() !== 'Ground Floor'
            || OperationalDepartment::FirstFloorKitchen->label() !== 'First Floor Kitchen') {
            throw new RuntimeException('an unknown department was accepted or labels changed');
        }
    },
    'staff roles separate payment authority from floor task authority' => static function (): void {
        if (!StaffRole::Cashier->allowsAction('confirm-payment')
            || StaffRole::Cashier->allowsAction('advance-station-task')
            || !StaffRole::GroundFloor->allowsAction('advance-station-task')
            || !StaffRole::GroundFloor->allowsAction('collect-order')
            || StaffRole::GroundFloor->allowsAction('confirm-payment')
            || StaffRole::GroundFloor->allowsAction('reprint-print-job')
            || !StaffRole::FirstFloorKitchen->allowsAction('advance-station-task')
            || StaffRole::FirstFloorKitchen->allowsAction('collect-order')
            || StaffRole::FirstFloorKitchen->allowsAction('confirm-payment')
            || StaffRole::Cashier->department() !== null
            || StaffRole::GroundFloor->department() !== OperationalDepartment::GroundFloor
            || StaffRole::FirstFloorKitchen->department() !== OperationalDepartment::FirstFloorKitchen) {
            throw new RuntimeException('staff role capabilities cross the cashier or floor boundary');
        }
    },
    'route status allows only explicit approval to become customer-orderable' => static function (): void {
        if (ProductRoutingStatus::tryFrom('approved') !== ProductRoutingStatus::Approved
            || ProductRoutingStatus::tryFrom('proposed') !== ProductRoutingStatus::Proposed
            || ProductRoutingStatus::tryFrom('owner_review') !== ProductRoutingStatus::OwnerReview
            || ProductRoutingStatus::tryFrom('unknown') !== null
            || ProductRoutingStatus::Proposed->label() === ProductRoutingStatus::Approved->label()) {
            throw new RuntimeException('route review statuses no longer distinguish approved, proposed, and unresolved products');
        }
    },
];
