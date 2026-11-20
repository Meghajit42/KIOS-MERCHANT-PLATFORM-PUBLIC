<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use DomainException;
use RuntimeException;

/** Validates owner uploads and writes immutable source files plus public responsive WebP derivatives. */
final class MenuImageService
{
    private const MAX_BYTES = 8_388_608;
    private const MAX_PIXELS = 20_000_000;

    private readonly string $privateRoot;
    private readonly string $publicRoot;

    public function __construct(?string $privateRoot = null, ?string $publicRoot = null)
    {
        $repoRoot = dirname(__DIR__, 2);
        $this->privateRoot = $privateRoot ?? $repoRoot;
        $this->publicRoot = $publicRoot ?? (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : $repoRoot . DIRECTORY_SEPARATOR . 'public');
    }

    /** @return array{reference: string, width: int, height: int} */
    public function store(string $temporaryPath, string $originalName, string $sku): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $sku) !== 1 || !is_file($temporaryPath)) {
            throw new DomainException('Choose a valid image file for this menu item.');
        }
        $size = filesize($temporaryPath);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new DomainException('Image files must be 8 MB or smaller.');
        }
        if (!extension_loaded('fileinfo') || !extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('Image upload is temporarily unavailable on this server.');
        }
        $bytes = file_get_contents($temporaryPath);
        if (!is_string($bytes)) {
            throw new DomainException('The image could not be read.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if (!is_string($mime) || !isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
            throw new DomainException('Upload a genuine JPEG, PNG, or WebP image with a matching file name.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !isset($info[0], $info[1]) || (int) $info[2] < 1) {
            throw new DomainException('The uploaded file is not a valid image.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 64 || $height < 64 || $width > 6000 || $height > 6000 || $width * $height > self::MAX_PIXELS) {
            throw new DomainException('Images must be at least 64 × 64 and no larger than 6000 × 6000 pixels.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new DomainException('The uploaded file could not be decoded as an image.');
        }

        $sha = hash('sha256', $bytes);
        $revision = $sha;
        $sourceDirectory = $this->privateRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'menu-images' . DIRECTORY_SEPARATOR . $sku;
        $derivativeDirectory = $this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'menu-managed' . DIRECTORY_SEPARATOR . $sku . DIRECTORY_SEPARATOR . $revision;
        if (!is_dir($sourceDirectory) && !mkdir($sourceDirectory, 0750, true) && !is_dir($sourceDirectory)) {
            imagedestroy($source);
            throw new RuntimeException('Private image storage is not writable.');
        }
        if (!is_dir($derivativeDirectory) && !mkdir($derivativeDirectory, 0755, true) && !is_dir($derivativeDirectory)) {
            imagedestroy($source);
            throw new RuntimeException('Public image storage is not writable.');
        }

        try {
            $original = $sourceDirectory . DIRECTORY_SEPARATOR . $sha . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            if (!is_file($original) && file_put_contents($original, $bytes, LOCK_EX) !== $size) {
                throw new RuntimeException('The original image could not be preserved.');
            }
            $targets = array_values(array_unique(array_filter([min(320, $width), 320, 640, 960], static fn(int $target): bool => $target <= $width)));
            foreach ($targets as $targetWidth) {
                $targetHeight = max(1, (int) round($targetWidth * 3 / 4));
                $derivative = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($derivative === false) {
                    throw new RuntimeException('An optimized image could not be created.');
                }
                $background=imagecolorallocate($derivative,255,248,231);imagefill($derivative,0,0,$background);imagealphablending($derivative, false);
                imagesavealpha($derivative, true);
                $ratio=min(1,$targetWidth/$width,$targetHeight/$height);$drawWidth=max(1,(int)round($width*$ratio));$drawHeight=max(1,(int)round($height*$ratio));
                if (!imagecopyresampled($derivative, $source, (int)(($targetWidth-$drawWidth)/2), (int)(($targetHeight-$drawHeight)/2), 0, 0, $drawWidth, $drawHeight, $width, $height)) {
                    imagedestroy($derivative);
                    throw new RuntimeException('An optimized image could not be rendered.');
                }
                $destination = $derivativeDirectory . DIRECTORY_SEPARATOR . $targetWidth . '.webp';
                if (!is_file($destination)) {
                    $temporary = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (!imagewebp($derivative, $temporary, 82) || !rename($temporary, $destination)) {
                        @unlink($temporary);
                        imagedestroy($derivative);
                        throw new RuntimeException('An optimized image could not be saved.');
                    }
                }
                imagedestroy($derivative);
            }
        } finally {
            imagedestroy($source);
        }
        return ['reference' => '/assets/menu-managed/' . $sku . '/' . $revision, 'width' => $width, 'height' => $height];
    }
}
