<?php
declare(strict_types=1);

// Run with a private client INI, a directory containing migrations 001–008,
// and a directory containing migration 009. Use an empty disposable schema
// named kios_gate_r2_timestamp_* on the loopback qualification instance.
use Kios\Infrastructure\MigrationRunner;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || $argc !== 5) {
    exit(2);
}
[, $clientFile, $database, $baseline, $candidate] = $argv;
if (!preg_match('/^kios_gate_r2_timestamp_[a-z0-9_]+$/D', $database)) {
    exit(2);
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new DomainException($message);
    }
}

try {
    $client = parse_ini_file($clientFile, true, INI_SCANNER_RAW)['client'];
    expect($client['host'] === '127.0.0.1' && $client['port'] === '49632', 'Unexpected database endpoint');
    $db = new PDO("mysql:host=127.0.0.1;port=49632;dbname=$database;charset=utf8mb4", $client['user'], $client['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    unset($client);
    $guard = $db->query('SELECT VERSION(), @@bind_address, @@port, DATABASE()')->fetch(PDO::FETCH_NUM);
    expect($guard[0] === '11.8.9-MariaDB' && $guard[1] === '127.0.0.1' && (int) $guard[2] === 49632
        && $guard[3] === $database, 'Unexpected database server');
    expect((int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0,
        'The timestamp regression needs an empty schema');

    $runner = new MigrationRunner($db);
    $applied = $runner->apply($baseline);
    expect(array_map(static fn(string $name): string => substr($name, 0, 3), $applied)
        === ['001', '002', '003', '004', '005', '006', '007', '008'], 'Unexpected baseline ledger');
    $db->exec("SET time_zone='+00:00'");
    $db->exec("INSERT INTO outlets (outlet_id,outlet_code,name) VALUES (1,'timestamp-test','Synthetic outlet')");
    $db->exec("INSERT INTO categories (category_id,outlet_id,slug,name) VALUES (1,1,'sweets','Synthetic sweets')");
    $db->exec("INSERT INTO preparation_stations (station_id,outlet_id,code,name)
        VALUES (1,1,'SWEETS','Synthetic sweets'),(2,1,'LEGACY','Synthetic unmapped station')");
    $db->exec("INSERT INTO products (product_id,outlet_id,category_id,station_id,sku,name,updated_at)
        VALUES (1,1,1,1,'test-sweet','Synthetic sweet','2020-01-02 03:04:05')");
    $db->exec("INSERT INTO product_variants (variant_id,product_id,code,label,unit,quantity_value,price_paise)
        VALUES (1,1,'single','One','piece',1,1000)");
    $db->exec("INSERT INTO orders (order_id,public_id,outlet_id,outlet_code_snapshot,token,order_type,status,total_paise) VALUES
        (1,'O-SYNTHETIC-1',1,'timestamp-test','G-0000000000000001','takeaway','preparing',1000),
        (2,'O-SYNTHETIC-2',1,'timestamp-test','G-0000000000000002','takeaway','preparing',1000)");
    $db->exec("INSERT INTO order_items (order_item_id,outlet_id,order_id,product_id,variant_id,product_sku_snapshot,
        variant_code_snapshot,station_id_snapshot,product_name_snapshot,variant_label_snapshot,unit_snapshot,quantity,unit_price_paise,line_total_paise)
        VALUES (1,1,2,1,1,'test-sweet','single',1,'Synthetic sweet','One','piece',1,1000,1000)");
    $db->exec("INSERT INTO preparation_tasks (preparation_task_id,outlet_id,order_id,station_id,updated_at) VALUES
        (1,1,1,1,'2020-02-03 04:05:06'),(2,1,2,2,'2020-03-04 05:06:07')");
    $db->exec('INSERT INTO preparation_task_items (outlet_id,order_id,preparation_task_id,order_item_id) VALUES (1,2,2,1)');

    expect($runner->apply($candidate) === ['009_operational_departments.sql'], 'Migration 009 did not apply exactly once');
    $product = $db->query('SELECT routing_status,updated_at FROM products WHERE product_id=1')->fetch();
    $tasks = $db->query('SELECT department_code_snapshot,updated_at FROM preparation_tasks ORDER BY preparation_task_id')->fetchAll();
    expect($product['routing_status'] === 'approved', 'Product routing backfill failed');
    expect(array_column($tasks, 'department_code_snapshot') === ['GROUND_FLOOR', 'GROUND_FLOOR'],
        'Direct or legacy fallback task routing failed');
    $changed = [];
    if ($product['updated_at'] !== '2020-01-02 03:04:05') {
        $changed[] = 'product';
    }
    if ($tasks[0]['updated_at'] !== '2020-02-03 04:05:06') {
        $changed[] = 'direct task';
    }
    if ($tasks[1]['updated_at'] !== '2020-03-04 05:06:07') {
        $changed[] = 'fallback task';
    }
    expect($changed === [], 'Historical timestamps changed: ' . implode(', ', $changed));

    // Advance only this connection's clock to verify automatic timestamps deterministically.
    $db->exec("SET timestamp=UNIX_TIMESTAMP('2022-01-02 00:00:00')");
    $db->exec("INSERT INTO products (product_id,outlet_id,category_id,station_id,sku,name)
        VALUES (2,1,1,1,'new-sweet','Synthetic new sweet')");
    $db->exec('INSERT INTO preparation_tasks (preparation_task_id,outlet_id,order_id,station_id) VALUES (3,1,1,2)');
    expect($db->query('SELECT updated_at FROM products WHERE product_id=2')->fetchColumn() === '2022-01-02 00:00:00'
        && $db->query('SELECT updated_at FROM preparation_tasks WHERE preparation_task_id=3')->fetchColumn() === '2022-01-02 00:00:00',
        'New records lost their default timestamp');
    $db->exec("SET timestamp=UNIX_TIMESTAMP('2022-01-02 00:01:00')");
    $db->exec("UPDATE products SET name='Synthetic edited sweet' WHERE product_id=2");
    $db->exec("UPDATE preparation_tasks SET status='preparing' WHERE preparation_task_id=3");
    expect($db->query('SELECT updated_at FROM products WHERE product_id=2')->fetchColumn() === '2022-01-02 00:01:00'
        && $db->query('SELECT updated_at FROM preparation_tasks WHERE preparation_task_id=3')->fetchColumn() === '2022-01-02 00:01:00',
        'Normal product or task updates stopped refreshing the timestamp');

    echo "PASS product and both task backfills preserve timestamps; new records retain normal timestamps.\n";
} catch (DomainException $error) {
    fwrite(STDERR, "FAIL {$error->getMessage()}\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "FAIL timestamp regression could not complete.\n");
    exit(2);
}
