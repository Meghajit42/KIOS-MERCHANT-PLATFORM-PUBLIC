<?php

declare(strict_types=1);

namespace Kios\Domain;

final readonly class PreparationStationCode
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $value)) {
            throw new \InvalidArgumentException('Invalid PreparationStationCode format.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
