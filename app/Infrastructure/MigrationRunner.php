<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> migration filenames applied in this run */
    public function apply(string $directory): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'migration VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $applied = $this->pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $already = array_fill_keys(array_map('strval', $applied), true);
        $files = glob(rtrim($directory, '\\/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $newlyApplied = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($already[$name])) {
                continue;
            }
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Migration file is empty or unreadable: ' . $name);
            }
            foreach ($this->statements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $insert = $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
            $insert->execute(['migration' => $name]);
            $newlyApplied[] = $name;
        }
        return $newlyApplied;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $statements), static fn(string $part): bool => $part !== ''));
    }
}
