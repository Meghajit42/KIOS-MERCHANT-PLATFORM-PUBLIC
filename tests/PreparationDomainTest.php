<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\Money;
use Kios\Domain\OrderItem;
use Kios\Domain\OrderStatus;
use Kios\Domain\OrderType;
use Kios\Domain\PreparationStationCode;
use Kios\Domain\PreparationStationGroup;
use Kios\Domain\PreparationTaskStatus;
use Kios\Domain\QuantityUnit;

return [
    'station code accepts configured uppercase names and rejects invalid values' => static function (): void {
        if ((string) new PreparationStationCode('SOUTH_INDIAN') !== 'SOUTH_INDIAN') {
            throw new RuntimeException('station code did not preserve its identifier');
        }
        foreach (['south_indian', 'SWEETS-1', '', str_repeat('A', 41)] as $invalid) {
            try {
                new PreparationStationCode($invalid);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('invalid station code was accepted');
        }
    },
    'station task state permits only queued to preparing to ready' => static function (): void {
        $queued = PreparationTaskStatus::Queued;
        $preparing = $queued->transitionTo(PreparationTaskStatus::Preparing);
        if ($preparing->transitionTo(PreparationTaskStatus::Ready) !== PreparationTaskStatus::Ready) {
            throw new RuntimeException('valid task state progression failed');
        }
        foreach ([[$queued, PreparationTaskStatus::Ready], [PreparationTaskStatus::Ready, PreparationTaskStatus::Preparing]] as [$from, $to]) {
            try {
                $from->transitionTo($to);
            } catch (DomainException) {
                continue;
            }
            throw new RuntimeException('invalid task state transition was accepted');
        }
    },
    'station group preserves non-empty item objects in order' => static function (): void {
        $first = new OrderItem('kaju', 'kaju-100g', 'Kaju Barfi', '100 g', QuantityUnit::Gram, 2, Money::fromPaise(15000));
        $second = new OrderItem('kaju', 'kaju-250g', 'Kaju Barfi', '250 g', QuantityUnit::Gram, 1, Money::fromPaise(36000));
        $group = new PreparationStationGroup(new PreparationStationCode('SWEETS'), [$first, $second]);
        if ($group->station->value !== 'SWEETS' || $group->items[0] !== $first || $group->items[1] !== $second) {
            throw new RuntimeException('station group did not preserve station or exact item order');
        }
        foreach ([[], ['not-an-order-item']] as $invalidItems) {
            try {
                new PreparationStationGroup(new PreparationStationCode('SWEETS'), $invalidItems);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('invalid station group items were accepted');
        }
    },
];
