<?php
declare(strict_types=1);

namespace Kios\Domain;

enum ProductRoutingStatus: string
{
    case Approved = 'approved';
    case Proposed = 'proposed';
    case OwnerReview = 'owner_review';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved for orders',
            self::Proposed => 'Category proposal · blocked pending confirmation',
            self::OwnerReview => 'Owner review required',
        };
    }
}
