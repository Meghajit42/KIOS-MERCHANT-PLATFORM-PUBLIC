<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class Product
{
    /** @var array<string, ProductVariant> */
    private array $variantsById;

    /** @param list<ProductVariant> $variants */
    public function __construct(
        public string $id,
        public string $name,
        public string $categoryId,
        public string $outletId,
        public PreparationStation $station,
        public bool $vegetarian,
        public bool $active,
        public bool $available,
        array $variants,
        public ?string $imageReference = null,
        public int $displayOrder = 0,
    ) {
        if (trim($id) === '' || trim($name) === '' || trim($categoryId) === '' || trim($outletId) === '') {
            throw new InvalidArgumentException('Product ID, name, category and outlet are required.');
        }
        if ($variants === []) {
            throw new InvalidArgumentException('A product must have at least one variant.');
        }

        $indexed = [];
        foreach ($variants as $variant) {
            if (!$variant instanceof ProductVariant) {
                throw new InvalidArgumentException('Product variants must be ProductVariant values.');
            }
            if (isset($indexed[$variant->id])) {
                throw new InvalidArgumentException('Variant IDs must be unique within a product.');
            }
            $indexed[$variant->id] = $variant;
        }
        $this->variantsById = $indexed;
    }

    public function variant(string $id): ?ProductVariant
    {
        return $this->variantsById[$id] ?? null;
    }

    /** @return list<ProductVariant> */
    public function variants(): array
    {
        return array_values($this->variantsById);
    }
}
