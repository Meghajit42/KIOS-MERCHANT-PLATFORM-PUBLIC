<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\Category;
use Kios\Domain\Product;

/** Static menu definitions filtered by the current database availability snapshot. */
final class CustomerMenuCatalog
{
    /** @var list<Category> */
    private array $categories = [];

    /** @var array<string, list<Product>> */
    private array $productsByCategory = [];

    /** @param list<string> $availableProductIds */
    public function __construct(array $availableProductIds)
    {
        $available = array_fill_keys($availableProductIds, true);
        foreach (DemoCatalog::products() as $product) {
            if ($product->active && $product->available && isset($available[$product->id])) {
                $this->productsByCategory[$product->categoryId][] = $product;
            }
        }
        foreach (DemoCatalog::categories() as $category) {
            if (!empty($this->productsByCategory[$category->id])) {
                $this->categories[] = $category;
            }
        }
    }

    /** @return list<Category> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return list<Product> */
    public function productsForCategory(string $categoryId): array
    {
        return $this->productsByCategory[$categoryId] ?? [];
    }

    public function category(string $categoryId): ?Category
    {
        foreach ($this->categories as $category) {
            if ($category->id === $categoryId) {
                return $category;
            }
        }
        return null;
    }
}
