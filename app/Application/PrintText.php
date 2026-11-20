<?php
declare(strict_types=1);

namespace Kios\Application;

final class PrintText
{
    public static function line(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public static function money(int $paise): string
    {
        if ($paise < 0) {
            throw new \InvalidArgumentException('A print amount cannot be negative.');
        }
        return '₹' . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
