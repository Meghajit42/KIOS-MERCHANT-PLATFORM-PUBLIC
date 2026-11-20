<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    private function __construct(private int $paise)
    {
    }

    public static function fromPaise(mixed $value): self
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Money must be a non-negative integer number of paise.');
        }
        return new self($value);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Parse a rupee amount without floating-point conversion. */
    public static function fromRupees(string $value): self
    {
        $value = trim($value);
        if (str_starts_with($value, '₹')) {
            $value = trim(substr($value, strlen('₹')));
        }
        if (str_contains($value, '₹')) {
            throw new InvalidArgumentException('Enter a positive price with up to two decimal places.');
        }
        if (!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Enter a positive price with up to two decimal places.');
        }
        $whole = (int) explode('.', $value, 2)[0];
        $fraction = isset($matches[1]) ? (int) str_pad($matches[1], 2, '0') : 0;
        $paise = ($whole * 100) + $fraction;
        if ($paise < 1 || $paise > 10_000_000) {
            throw new InvalidArgumentException('Price must be between ₹0.01 and ₹100,000.00.');
        }
        return new self($paise);
    }

    public function paise(): int
    {
        return $this->paise;
    }

    public function add(self $other): self
    {
        if ($other->paise > PHP_INT_MAX - $this->paise) {
            throw new OverflowException('Money total exceeds the supported range.');
        }
        return new self($this->paise + $other->paise);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException('Money multiplier cannot be negative.');
        }
        if ($factor !== 0 && $this->paise > intdiv(PHP_INT_MAX, $factor)) {
            throw new OverflowException('Money total exceeds the supported range.');
        }
        return new self($this->paise * $factor);
    }

    public function formatRupees(): string
    {
        return '₹' . intdiv($this->paise, 100) . '.' . str_pad((string) ($this->paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
