<?php
declare(strict_types=1);

namespace Kios\Domain;

enum PrintJobStatus: string
{
    case Queued = 'queued';
    case Printing = 'printing';
    case Printed = 'printed';
    case RetryPending = 'retry_pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Claimed = 'claimed';
    case SubmittedUnconfirmed = 'submitted_unconfirmed';
    case NeedsReview = 'needs_review';
    case ReprintRequested = 'reprint_requested';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Queued => [self::Printing, self::Failed, self::Cancelled],
            self::Printing => [self::Printed, self::RetryPending, self::Failed],
            self::RetryPending => [self::Printing, self::Failed, self::Cancelled],
            self::Failed => [self::RetryPending, self::Cancelled],
            self::Printed, self::Cancelled => [],
            self::Claimed => [self::SubmittedUnconfirmed, self::Failed, self::NeedsReview],
            self::ReprintRequested => [self::Claimed, self::Cancelled],
            self::SubmittedUnconfirmed, self::NeedsReview => [],
        }, true);
    }

    public function transitionTo(self $next): self
    {
        if (!$this->canTransitionTo($next)) {
            throw new \DomainException("Invalid print job transition: {$this->value} to {$next->value}.");
        }
        return $next;
    }
}
