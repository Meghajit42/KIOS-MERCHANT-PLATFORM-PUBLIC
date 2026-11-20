<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use RuntimeException;
use Throwable;

final class DatabaseConnectionException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionStage,
        public readonly int $attempt,
        public readonly bool $transient,
        public readonly ?string $sqlState,
        public readonly ?int $driverCode,
        Throwable $previous,
    ) {
        parent::__construct('The database service is temporarily unavailable.', 0, $previous);
    }
}
