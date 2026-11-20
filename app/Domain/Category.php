<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class Category
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active = true,
        public int $sortOrder = 0,
    ) {
        if (trim($id) === '' || trim($name) === '') {
            throw new InvalidArgumentException('Category ID and name are required.');
        }
    }
}
