<?php

declare(strict_types=1);

namespace Kios\Domain;

use DomainException;

enum PreparationTaskStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => $next === self::Preparing,
            self::Preparing => $next === self::Ready,
            default => false,
        };
    }

    public function transitionTo(self $next): self
    {
        if ($this->canTransitionTo($next)) {
            return $next;
        }

        throw new DomainException("Transition from {$this->value} to {$next->value} is not allowed.");
    }
}
