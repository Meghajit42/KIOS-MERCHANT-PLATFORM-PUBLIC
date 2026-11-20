<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use RuntimeException;

final readonly class DatabaseConfiguration
{
    private function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
    ) {
    }

    public static function fromEnvironment(string $prefix): self
    {
        $mode = Environment::get('APP_ENV', 'local');
        if (!in_array($mode, ['local', 'demo'], true)) {
            throw new RuntimeException('Only local and hosted demo database modes are supported.');
        }
        $demoMode = $mode === 'demo';
        $host = Environment::get($prefix . '_HOST', $demoMode ? null : '127.0.0.1');
        $port = Environment::get($prefix . '_PORT', $demoMode ? null : '33081');
        $database = Environment::get($prefix . '_NAME', $demoMode ? null : 'kios_development');
        $username = Environment::get($prefix . '_USER');
        $password = Environment::get($prefix . '_PASSWORD');

        if (!is_string($host) || $host === '' || !is_string($port) || !ctype_digit($port)
            || !is_string($database) || $database === '' || $username === null || $username === ''
            || $password === null || $password === '') {
            throw new RuntimeException($demoMode
                ? 'Hosted demo database configuration is incomplete.'
                : 'Local Kios database configuration is incomplete.');
        }
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        $validHost = preg_match('/^(?=.{1,253}\z)(?:' . $label . ')(?:\.' . $label . ')*$/D', $host) === 1;
        if (!$validHost
            || !preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('The configured database endpoint is invalid.');
        }
        if (!$demoMode && ($host !== '127.0.0.1' || $port !== '33081' || $database !== 'kios_development')) {
            throw new RuntimeException('Local Kios is restricted to its dedicated loopback development database.');
        }

        return new self($host, (int) $port, $database, $username, $password);
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }
}
