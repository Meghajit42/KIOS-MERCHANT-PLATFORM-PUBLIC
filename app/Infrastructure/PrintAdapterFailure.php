<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Kios\Domain\PrintErrorCategory;
use RuntimeException;

final class PrintAdapterFailure extends RuntimeException
{
    public function __construct(
        public readonly PrintErrorCategory $category,
        string $safeMessage,
        public readonly bool $retryable = false,
    ) {
        parent::__construct(substr($safeMessage, 0, 180));
    }
}
