<?php
declare(strict_types=1);

/** Deterministic documentation/source contract checks; synthetic/static only. */
$root = $argv[1] ?? dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        throw new RuntimeException('Missing contract input: ' . $relative);
    }
    return (string) file_get_contents($path);
};
$source = $read('app/CustomerOrders/OrderIntents.php');
$migration = $read('database/migrations/037_customer_order_intents.sql');
$architecture = $read('docs/M07D_ORDER_LIFECYCLE_ARCHITECTURE.md');
$states = $read('docs/M07D_ORDER_STATE_CONTRACT.md');
$internal = $read('docs/M07D_INTERNAL_ORDER_CONTRACT.md');
$boundary = $read('docs/M07D_RESTAURANT_INTEGRATION_BOUNDARY.md');
$privacy = $read('docs/M07D_SECURITY_AND_PRIVACY.md');
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void { $checks[$name] = $ok; };

$check('All four requested documents and security supplement exist', true);
$check('Implemented service methods and signatures are documented',
    preg_match('/public function create\(string \$c,string \$m,string \$o,int \$accountVersion,string \$request,array \$lines\):array/', $source) === 1
    && preg_match('/public function read\(string \$c,string \$m,string \$o,string \$id\):array/', $source) === 1
    && preg_match('/public function transition\(string \$c,string \$m,string \$o,int \$accountVersion,string \$request,string \$id,int \$expected,string \$next\):array/', $source) === 1
    && str_contains($internal, 'create(string $c, string $m, string $o, int $accountVersion, string $request, array $lines)')
    && str_contains($internal, 'read(string $c, string $m, string $o, string $id)')
    && str_contains($internal, 'transition(string $c, string $m, string $o, int $accountVersion, string $request, string $id, int $expected, string $next)'));
$check('Documented lifecycle matches implementation and terminal states',
    str_contains($source, "'DRAFT'=>['SUBMITTED','CANCELLED','EXPIRED']")
    && str_contains($source, "'SUBMITTED'=>['PENDING_MERCHANT_ACCEPTANCE','CANCELLED','EXPIRED']")
    && str_contains($source, "'PENDING_MERCHANT_ACCEPTANCE'=>['ACCEPTED','REJECTED','CANCELLED','EXPIRED']")
    && str_contains($states, '`DRAFT` | `SUBMITTED`, `CANCELLED`, `EXPIRED`')
    && str_contains($states, '`SUBMITTED` | `PENDING_MERCHANT_ACCEPTANCE`, `CANCELLED`, `EXPIRED`')
    && str_contains($states, '`PENDING_MERCHANT_ACCEPTANCE` | `ACCEPTED`, `REJECTED`, `CANCELLED`, `EXPIRED`')
    && str_contains($states, '`ACCEPTED` | none') && str_contains($states, '`REJECTED` | none')
    && str_contains($states, '`CANCELLED` | none') && str_contains($states, '`EXPIRED` | none'));
$check('Selection fields and validation limits are documented accurately',
    str_contains($source, "['productId','variantId','quantity','productVersion','variantVersion']")
    && str_contains($source, '$line[\'quantity\']>99') && str_contains($source, 'count($lines)>50')
    && str_contains($internal, "'variantVersion' => 1")
    && str_contains($internal, 'Price, line total, product name, unit, serving label, and category are not accepted as input'));
$check('Return projection and immutable quote fields match source',
    str_contains($source, "'intentId'=>\$r['intent_id']") && str_contains($source, "'simulationOnly'=>true")
    && str_contains($source, "'catalogueFingerprint'=>hash('sha256'")
    && str_contains($internal, 'intentId, customerId, merchantId, outletId, status, version,')
    && str_contains($internal, 'catalogueFingerprint'));
$check('Native catalogue eligibility and integer paise pricing are documented',
    str_contains($source, 'customerProductsForCategory($code,$row[\'slug\'])')
    && str_contains($source, '$v->unitPrice->multiply($line[\'quantity\'])')
    && str_contains($source, '$total=$total->add($amount)')
    && str_contains($architecture, 'MenuRepository::customerProductsForCategory()')
    && str_contains($architecture, 'Integer paise'));
$check('Ownership, authorization, entitlements, locks and feature gates match source',
    str_contains($source, 'currentCustomerId()') && str_contains($source, 'Permission::ReadOrders')
    && str_contains($source, "requireFeature(\$m,\$o,'menu','read')") && str_contains($source, 'FOR UPDATE')
    && str_contains($source, 'KIOS_CUSTOMER_ORDER_INTENTS_ENABLED')
    && str_contains($architecture, 'CustomerIdentityProvider') && str_contains($privacy, 'Customer isolation'));
$check('READ COMMITTED transaction and rollback behavior are documented',
    str_contains($source, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')
    && str_contains($source, '$this->db->beginTransaction()') && str_contains($source, '$this->db->commit()')
    && str_contains($source, '$this->db->rollBack()') && str_contains($states, 'A failed event write rolls back the mutation'));
$check('Idempotency, stale-version and database uniqueness rules match source',
    str_contains($source, "'/^[a-f0-9]{32}$/D'") && str_contains($source, "hash_equals(\$p['payload_hash'],\$hash)")
    && str_contains($source, "(int)\$r['version']!==\$expected") && str_contains($migration, 'PRIMARY KEY')
    && str_contains($migration, 'UNIQUE(intent_id,intent_version)')
    && str_contains($internal, 'same customer, merchant, outlet, internal actor, and payload returns the original receipt'));
$check('Migration 037 table, FK, trigger, simulation and epoch claims match',
    str_contains($migration, 'CREATE TABLE customer_order_intents')
    && str_contains($migration, 'CREATE TABLE customer_order_intent_lines')
    && str_contains($migration, 'CREATE TABLE customer_order_intent_events')
    && substr_count($migration, 'CREATE TRIGGER ') === 6
    && str_contains($migration, "mode ENUM('SIMULATION')") && str_contains($migration, 'created_epoch BIGINT NOT NULL')
    && str_contains($architecture, 'Migration `037_customer_order_intents.sql`'));
$check('Restaurant handoff is future-only and no native side effect is claimed',
    !preg_match('/new\s+(?:OrderRepository|StaffPos|TenantOperations)\s*\(/', $source)
    && str_contains($boundary, 'no implemented handoff')
    && str_contains($boundary, 'does not create an **authoritative restaurant order / POS / KOT**')
    && str_contains($boundary, 'separately authorized') && str_contains($privacy, 'No live order, POS/KOT'));
$check('Security, privacy, retention limitations and public restrictions are explicit',
    str_contains($privacy, 'retention schedule') && str_contains($privacy, 'tenant isolation')
    && str_contains($privacy, 'Public customer registration, public authentication, public ordering')
    && str_contains($states, 'does not describe the native restaurant order lifecycle'));
$check('New documentation contains no recognized credential patterns or email addresses',
    !preg_match('/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----)\b/', $architecture.$states.$internal.$boundary.$privacy)
    && !preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $architecture.$states.$internal.$boundary.$privacy));

$passed = count(array_filter($checks));
foreach ($checks as $name => $ok) { fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL); }
fwrite(STDOUT, sprintf("RESULT: %d/%d PASS, %d FAIL\n", $passed, count($checks), count($checks) - $passed));
exit($passed === count($checks) ? 0 : 1);