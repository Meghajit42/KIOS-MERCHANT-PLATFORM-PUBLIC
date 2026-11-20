<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use RuntimeException;

final class MenuBackupExporter
{
    public function create(PDO $pdo, string $applicationRoot): string
    {
        if (Environment::get('APP_ENV', 'local') !== 'demo') {
            throw new RuntimeException('Menu backup is restricted to the Kios demo environment.');
        }

        $root = realpath($applicationRoot);
        $public = realpath($applicationRoot . DIRECTORY_SEPARATOR . 'public');
        $runtime = realpath($applicationRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime');
        if ($root === false || $public === false || $runtime === false || !is_dir($runtime)
            || !str_starts_with($runtime, $root . DIRECTORY_SEPARATOR)
            || str_starts_with($runtime, $public . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The private Kios runtime directories are unavailable.');
        }

        $backupDirectory = $runtime . DIRECTORY_SEPARATOR . 'menu-backups';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new RuntimeException('The private Kios menu backup directory could not be created.');
        }
        $backupDirectory = realpath($backupDirectory);
        if ($backupDirectory === false
            || !str_starts_with($backupDirectory, $runtime . DIRECTORY_SEPARATOR)
            || str_starts_with($backupDirectory, $public . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Menu backups must remain under private Kios runtime storage.');
        }

        $outlet = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
        $outlet->execute(['code' => 'demo-outlet']);
        $outletId = $outlet->fetchColumn();
        if ($outletId === false) {
            throw new RuntimeException('The active Kios demo outlet is unavailable.');
        }
        $outletId = (int) $outletId;

        $data = [
            'backup_version' => 1,
            'menu_version' => 'before_' . \Kios\Application\GopalMenuV1::VERSION,
            'created_at_utc' => gmdate(DATE_ATOM),
            'outlet_code' => 'demo-outlet',
            'scope' => ['categories', 'preparation_stations', 'products', 'product_variants'],
            'counts' => [],
            'tables' => [],
        ];

        $queries = [
            'categories' => 'SELECT * FROM categories WHERE outlet_id = :outlet_id ORDER BY sort_order, category_id',
            'preparation_stations' => 'SELECT * FROM preparation_stations WHERE outlet_id = :outlet_id ORDER BY station_id',
            'products' => 'SELECT * FROM products WHERE outlet_id = :outlet_id ORDER BY product_id',
            'product_variants' => 'SELECT pv.* FROM product_variants pv JOIN products p ON p.product_id = pv.product_id '
                . 'WHERE p.outlet_id = :outlet_id ORDER BY pv.variant_id',
        ];
        foreach ($queries as $table => $sql) {
            $query = $pdo->prepare($sql);
            $query->execute(['outlet_id' => $outletId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $data['tables'][$table] = $rows;
            $data['counts'][$table] = count($rows);
        }

        $filename = 'k5.10-before-gopal-menu-v1-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.json';
        $path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payload = $json . PHP_EOL;
        if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload)) {
            throw new RuntimeException('The Kios menu rollback export could not be written.');
        }
        @chmod($path, 0600);

        return 'storage/runtime/menu-backups/' . $filename;
    }
}
