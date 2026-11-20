<?php
declare(strict_types=1);

namespace Kios\Domain;

use Closure;
use RuntimeException;

final class TokenGenerator
{
    private Closure $bytes;

    /** @param null|callable(int): string $bytesSource */
    public function __construct(?callable $bytesSource = null)
    {
        $this->bytes = Closure::fromCallable($bytesSource ?? 'random_bytes');
    }

    /** @param list<string> $existingTokens */
    public function generate(array $existingTokens): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $bytes = ($this->bytes)(8);
            if (!is_string($bytes) || strlen($bytes) !== 8) {
                throw new RuntimeException('Token source did not return the required bytes.');
            }
            $candidate = 'G-' . strtoupper(bin2hex($bytes));
            if (!in_array($candidate, $existingTokens, true)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Could not generate a unique order token.');
    }
}
