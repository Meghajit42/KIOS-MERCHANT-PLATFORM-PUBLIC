<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\Product;
use RuntimeException;

/** Exact, manifest-derived image routing for the Gopal demo menu. */
final class GopalMenuImageCatalog
{
    /** @var array{schema_version: int, images: array<string, array<string, mixed>>, fallbacks: array<string, array<string, mixed>>}|null */
    private static ?array $catalog = null;

    public static function render(Product $product, bool $thumbnail = false, bool $priority = false): string
    {
        // Public export: exact local synthetic placeholder needs no omitted shop image maps.
        if ($product->imageReference === '/assets/brand-product-placeholder.svg') {
            return '<img class="' . ($thumbnail ? 'cart-line-image' : 'legacy-product-image') . '" src="/assets/brand-product-placeholder.svg" alt="Synthetic product placeholder" loading="' . ($priority ? 'eager' : 'lazy') . '" decoding="async">';
        }
        if ($product->imageReference !== null && self::isManagedReference($product->imageReference)) {
            return self::renderManaged($product, $product->imageReference, $thumbnail, $priority);
        }
        $image = self::imageFor($product);
        if ($image !== null) {
            return self::renderPhotograph($product, $image, $thumbnail, $priority);
        }

        if (self::isPending($product)) {
            return self::renderPending($product, $thumbnail, $priority);
        }

        $class = $thumbnail ? 'cart-line-image' : 'legacy-product-image';
        $loading = $priority && !$thumbnail ? 'eager' : 'lazy';
        $priorityAttribute = $priority && !$thumbnail ? ' fetchpriority="high"' : '';
        return '<img class="' . $class . '" src="' . self::escape($product->imageReference ?? '')
            . '" alt="Illustrative category artwork, not a dish photograph" loading="' . $loading . '"'
            . $priorityAttribute . ' decoding="async">';
    }

    /** @return array<string, mixed>|null */
    public static function imageFor(Product $product): ?array
    {
        $extra = self::extraImages()[$product->id] ?? null;
        if (is_array($extra)) { return $extra; }
        $voice = self::voiceCatalog();
        foreach ($voice['images'] as $candidate) {
            if (($candidate['product_sku'] ?? null) === $product->id) { return $candidate; }
        }
        $entry = $voice['images'][$product->name] ?? null;
        if (is_array($entry) && ($entry['category'] ?? null) === $product->categoryId) { return $entry; }
        $image = self::catalog()['images'][$product->name] ?? null;
        if (is_array($image) && ($image['category'] ?? null) === $product->categoryId) {
            return $image;
        }
        // Stable seeded SKUs retain their original image when owners rename or recategorize them.
        $seeded = DemoCatalog::findProduct($product->id);
        if ($seeded !== null) {
            $seedImage = self::catalog()['images'][$seeded->name] ?? null;
            if (is_array($seedImage) && ($seedImage['category'] ?? null) === $seeded->categoryId) {
                return $seedImage;
            }
        }
        return null;
    }

    public static function hasPendingImage(Product $product): bool
    {
        return self::isPending($product);
    }

    /** @return array{schema_version: int, images: array<string, array<string, mixed>>, fallbacks: array<string, array<string, mixed>>} */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $path = dirname(__DIR__) . '/Data/gopal-menu-image-map.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new RuntimeException('The Gopal menu image map is unavailable.');
        }
        $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || ($catalog['schema_version'] ?? null) !== 1
            || !is_array($catalog['images'] ?? null) || !is_array($catalog['fallbacks'] ?? null)) {
            throw new RuntimeException('The Gopal menu image map has an unsupported format.');
        }

        $versionedPath = dirname(__DIR__) . '/Data/gopal-menu-image-map-v2.json';
        $versionedContents = is_file($versionedPath) ? file_get_contents($versionedPath) : false;
        if (!is_string($versionedContents)) {
            throw new RuntimeException('The Gopal menu image v2 map is unavailable.');
        }
        $versioned = json_decode($versionedContents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($versioned) || ($versioned['schema_version'] ?? null) !== 2
            || !is_array($versioned['images'] ?? null)) {
            throw new RuntimeException('The Gopal menu image v2 map has an unsupported format.');
        }
        foreach ($versioned['images'] as $name => $image) {
            if (!is_string($name) || !is_array($image)
                || (!isset($catalog['images'][$name]) && !isset($catalog['fallbacks'][$name]))) {
                throw new RuntimeException('The Gopal menu image v2 map references an unknown product.');
            }
        }
        $catalog['images'] = array_replace($catalog['images'], $versioned['images']);
        foreach (array_keys($versioned['images']) as $name) {
            unset($catalog['fallbacks'][$name]);
        }
        self::$catalog = $catalog;
        return self::$catalog;
    }

    /** @param array<string, mixed> $image */
    private static function renderPhotograph(Product $product, array $image, bool $thumbnail, bool $priority): string
    {
        $base = (string) ($image['asset_base'] ?? '');
        $version = substr((string) ($image['source_sha256'] ?? ''), 0, 16);
        $widths = $image['widths'] ?? [];
        $sourceWidth = (int) ($image['source_width'] ?? 0);
        $sourceHeight = (int) ($image['source_height'] ?? 0);
        if (preg_match('~^/assets/menu(?:-v2)?/[a-z0-9-]+/[a-z0-9_-]+$~D', $base) !== 1
            || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1
            || !is_array($widths) || $widths === [] || $sourceWidth < 1 || $sourceHeight < 1) {
            throw new RuntimeException('A Gopal menu image entry is invalid.');
        }

        $sourceSet = [];
        foreach ($widths as $width) {
            if (!is_int($width) || $width < 1 || $width > $sourceWidth) {
                throw new RuntimeException('A Gopal menu image size would upscale its source.');
            }
            $sourceSet[] = self::url($base, $width, $version) . ' ' . $width . 'w';
        }
        $sizes = $thumbnail
            ? '82px'
            : '(max-width: 599px) calc(100vw - 2rem), (max-width: 899px) calc((100vw - 3rem) / 2), (max-width: 1199px) calc((100vw - 20rem) / 2), 310px';
        $defaultWidth = in_array(480, $widths, true) ? 480 : max($widths);
        $imageClass = $thumbnail ? 'cart-line-image' : 'menu-product-image';
        $loading = $priority && !$thumbnail ? 'eager' : 'lazy';
        $priorityAttribute = $priority && !$thumbnail ? ' fetchpriority="high"' : '';

        $description = ($image['illustrative'] ?? false) === true ? ' illustrative image' : ' dish photograph';
        return '<picture class="' . $imageClass . '"><source type="image/webp" srcset="'
            . self::escape(implode(', ', $sourceSet)) . '" sizes="' . $sizes . '"><img src="'
            . self::escape(self::url($base, $defaultWidth, $version)) . '" alt="'
            . self::escape($product->name . $description) . '" width="' . $sourceWidth
            . '" height="' . $sourceHeight . '" loading="' . $loading . '"' . $priorityAttribute
            . ' decoding="async"></picture>';
    }

    private static function renderPending(Product $product, bool $thumbnail, bool $priority): string
    {
        $class = $thumbnail ? 'cart-line-image-pending' : 'menu-image-pending';
        $loading = $priority && !$thumbnail ? 'eager' : 'lazy';
        return '<div class="' . $class . '" role="img" aria-label="' . self::escape($product->name)
            . ' image coming soon"><img src="/assets/brand-product-placeholder.svg" alt="" width="54" height="48" loading="'
            . $loading . '"'
            . ($priority && !$thumbnail ? ' fetchpriority="high"' : '') . ' decoding="async">'
            . '<span>Image coming soon</span></div>';
    }

    private static function voiceCatalog(): array
    {
        static $map = null;
        if ($map !== null) { return $map; }
        $path = dirname(__DIR__) . '/Data/gopal-voice-image-map.json';
        if (!is_file($path)) { return $map = ['images' => [], 'fallbacks' => []]; }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || !is_array($data['images'] ?? null) || !is_array($data['fallbacks'] ?? null)) {
            throw new RuntimeException('The voice image manifest is invalid.');
        }
        return $map = $data;
    }

    /** @return array<string, array<string, mixed>> */
    private static function extraImages(): array
    {
        static $images = null;
        if ($images !== null) return $images;
        $path = dirname(__DIR__) . '/Data/k9-extra-image-map.json';
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) throw new RuntimeException('The additional menu image map is unavailable.');
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || !is_array($data['images'] ?? null)) {
            throw new RuntimeException('The additional menu image map is invalid.');
        }
        return $images = $data['images'];
    }

    private static function isPending(Product $product): bool
    {
        $fallback = self::voiceCatalog()['fallbacks'][$product->name] ?? self::catalog()['fallbacks'][$product->name] ?? null;
        return is_array($fallback)
            && ($fallback['status'] ?? null) === 'IMAGE_PENDING'
            && ($fallback['category'] ?? null) === $product->categoryId;
    }

    private static function isManagedReference(string $reference): bool
    {
        return preg_match('~^/assets/menu-managed/[a-z0-9][a-z0-9_-]{0,79}/[a-f0-9]{16,64}$~D', $reference) === 1;
    }

    private static function renderManaged(Product $product, string $reference, bool $thumbnail, bool $priority): string
    {
        $root = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')
            : dirname(__DIR__, 2) . '/public';
        $sourceSet = [];
        foreach ([320, 640, 960] as $width) {
            $path = $reference . '/' . $width . '.webp';
            if (is_file($root . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                $sourceSet[] = $path . ' ' . $width . 'w';
            }
        }
        if ($sourceSet === []) {
            return '<img class="menu-image-pending" src="/assets/brand-product-placeholder.svg" alt="Image coming soon for '
                . self::escape($product->name) . '" loading="lazy" decoding="async">';
        }
        $loading = $priority && !$thumbnail ? 'eager' : 'lazy';
        return '<img class="' . ($thumbnail ? 'cart-line-image' : 'menu-product-image') . '" src="'
            . self::escape(explode(' ', $sourceSet[0])[0]) . '" srcset="' . self::escape(implode(', ', $sourceSet))
            . '" sizes="' . ($thumbnail ? '82px' : '(max-width: 599px) calc(100vw - 2rem), (max-width: 899px) calc((100vw - 3rem) / 2), (max-width: 1199px) calc((100vw - 20rem) / 2), 310px')
            . '" alt="' . self::escape($product->name . ' dish photograph') . '" loading="' . $loading . '"'
            . ($priority && !$thumbnail ? ' fetchpriority="high"' : '') . ' decoding="async">';
    }

    private static function url(string $base, int $width, string $version): string
    {
        return $base . '/' . $width . '.webp?v=' . $version;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
