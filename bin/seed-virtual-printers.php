<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;

try {
    $pdo = Database::connect();
    $outletQuery = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $outletQuery->execute(['code' => 'demo-outlet']);
    $outletId = $outletQuery->fetchColumn();
    if ($outletId === false) {
        throw new RuntimeException('The synthetic demo outlet is unavailable.');
    }
    $outletId = (int) $outletId;
    $pdo->beginTransaction();
    $printer = $pdo->prepare(
        'INSERT INTO printers (outlet_id, printer_code, name, role, adapter_type, configuration_ref, paper_width_mm, cut_after_print, enabled) '
        . 'VALUES (:outlet_id, :code, :name, :role, \'virtual\', NULL, 80, 1, 1) '
        . 'ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), adapter_type = \'virtual\', '
        . 'configuration_ref = NULL, paper_width_mm = 80, cut_after_print = 1, enabled = 1'
    );
    $printer->execute(['outlet_id' => $outletId, 'code' => 'k5-virtual-receipt', 'name' => 'Kios Virtual Receipt', 'role' => 'cashier_receipt']);
    $printer->execute(['outlet_id' => $outletId, 'code' => 'k5-virtual-kot', 'name' => 'Kios Virtual Shared KOT', 'role' => 'station_kot']);
    $findPrinter = $pdo->prepare('SELECT printer_id FROM printers WHERE outlet_id = :outlet AND printer_code = :code AND enabled = 1');
    $findPrinter->execute(['outlet' => $outletId, 'code' => 'k5-virtual-receipt']);
    $receiptId = (int) $findPrinter->fetchColumn();
    $findPrinter->execute(['outlet' => $outletId, 'code' => 'k5-virtual-kot']);
    $kotId = (int) $findPrinter->fetchColumn();

    $conflict = $pdo->prepare(
        'SELECT COUNT(*) FROM printer_station_routes r JOIN printers p '
        . 'ON p.outlet_id = r.outlet_id AND p.printer_id = r.printer_id '
        . 'WHERE r.outlet_id = :outlet AND p.printer_code <> :code'
    );
    $conflict->execute(['outlet' => $outletId, 'code' => 'k5-virtual-kot']);
    if ((int) $conflict->fetchColumn() > 0) {
        throw new RuntimeException('Existing station routes use a different printer; no routes were changed.');
    }

    $route = $pdo->prepare(
        'INSERT INTO printer_station_routes (outlet_id, station_id, printer_id) '
        . 'SELECT :outlet, station_id, :printer FROM preparation_stations WHERE outlet_id = :station_outlet AND active = 1 '
        . 'ON DUPLICATE KEY UPDATE printer_id = VALUES(printer_id)'
    );
    $route->execute(['outlet' => $outletId, 'printer' => $kotId, 'station_outlet' => $outletId]);
    $count = $pdo->prepare('SELECT COUNT(*) FROM printer_station_routes WHERE outlet_id = :outlet AND printer_id = :printer');
    $count->execute(['outlet' => $outletId, 'printer' => $kotId]);
    $routeCount = (int) $count->fetchColumn();
    $pdo->commit();
    echo "Configured virtual receipt and shared KOT printers; station routes: {$routeCount}.\n";
} catch (Throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Virtual printer setup failed. Check the Kios database configuration and existing route state.\n");
    exit(1);
}
