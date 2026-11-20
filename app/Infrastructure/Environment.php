<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use RuntimeException;

final class Environment
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $file = dirname(__DIR__, 2) . '/.env.local';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                throw new RuntimeException('Local configuration could not be read.');
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/D', $line, $matches)) {
                    throw new RuntimeException('Local configuration contains an invalid entry.');
                }
                if (getenv($matches[1]) === false) {
                    putenv($matches[1] . '=' . $matches[2]);
                    $_ENV[$matches[1]] = $matches[2];
                }
            }
        }
        $timezone = getenv('APP_TIMEZONE');
        if (!is_string($timezone) || trim($timezone) === '') {
            $timezone = 'Asia/Kolkata';
        }
        if (!@date_default_timezone_set($timezone)) {
            throw new RuntimeException('The configured application timezone is invalid.');
        }
        self::$loaded = true;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        self::load();
        $value = getenv($name);
        return $value === false ? $default : $value;
    }
}
