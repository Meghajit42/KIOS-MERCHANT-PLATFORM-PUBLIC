<?php
declare(strict_types=1);

// Deterministic source-aware checks for the M07E Shift 02 documentation contract.
$root = $argv[1] ?? dirname(__DIR__, 2);
$root = rtrim($root, DIRECTORY_SEPARATOR);
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void { $checks[] = [$name, $ok]; };
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) { throw new RuntimeException('Required file missing: ' . $relative); }
    return file_get_contents($path) ?: '';
};

try {
    $accounts = $read('app/DeliveryIdentity/PartnerAccounts.php');
    $provider = $read('app/DeliveryIdentity/PartnerIdentityProvider.php');
    $request = $read('app/DeliveryIdentity/FutureDeliveryRequest.php');
    $migration = $read('database/migrations/038_delivery_partner_identity.sql');
    $env = $read('.env.example');
    $architecture = $read('docs/M07E_DELIVERY_ARCHITECTURE.md');
    $states = $read('docs/M07E_PARTNER_STATE_CONTRACT.md');
    $service = $read('docs/M07E_INTERNAL_DELIVERY_CONTRACT.md');
    $dispatch = $read('docs/M07E_DISPATCH_INTEGRATION_BOUNDARY.md');
    $privacy = $read('docs/M07E_DELIVERY_SECURITY_PRIVACY.md');

    $check('all required documentation files exist and are readable', true);
    $lifecycle = preg_match('/\$allowed\s*=\s*\[(.*?)\];/s', $accounts, $m) === 1 ? $m[1] : '';
    $check('documented account lifecycle edges match source',
        str_contains($lifecycle, "'PENDING'=>['ACTIVE','DEACTIVATED']") &&
        str_contains($lifecycle, "'ACTIVE'=>['SUSPENDED','DEACTIVATED']") &&
        str_contains($lifecycle, "'SUSPENDED'=>['ACTIVE','DEACTIVATED']") &&
        str_contains($lifecycle, "'DEACTIVATED'=>[]") &&
        str_contains($states, '`PENDING -> ACTIVE`') && str_contains($states, '`PENDING -> DEACTIVATED`') &&
        str_contains($states, '`ACTIVE -> SUSPENDED`') && str_contains($states, '`ACTIVE -> DEACTIVATED`') &&
        str_contains($states, '`SUSPENDED -> ACTIVE`') && str_contains($states, '`SUSPENDED -> DEACTIVATED`') &&
        str_contains($states, 'No outgoing transition is allowed'));
    $check('availability and eligibility enums/guards match source',
        str_contains($accounts, "['ELIGIBLE','INELIGIBLE']") &&
        str_contains($accounts, "['OFFLINE','AVAILABLE','TEMPORARILY_UNAVAILABLE']") &&
        str_contains($accounts, "'eligibility']==='ELIGIBLE'") &&
        str_contains($states, 'TEMPORARILY_UNAVAILABLE') && str_contains($states, 'nonempty configured service areas and capabilities'));

    $signatures = [
        'create(AuthenticatedUserProvider $platform,string $handle,string $name): array',
        'transition(AuthenticatedUserProvider $platform,string $id,int $expected,string $next): array',
        'eligibility(AuthenticatedUserProvider $platform,string $id,int $expected,string $next): array',
        'profile(PartnerIdentityProvider $auth,string $id): array',
        'updateProfile(PartnerIdentityProvider $auth,string $id,int $expected,string $name): array',
        'configure(PartnerIdentityProvider $auth,string $id,int $expected,array $areas,array $capabilities): array',
        'availability(PartnerIdentityProvider $auth,string $id,int $expected,string $next): array',
    ];
    $sigOk = true;
    foreach ($signatures as $signature) {
        $normalizedSignature = preg_replace('/\s+/', '', $signature);
        $compact = preg_replace('/\s+/', '', $accounts);
        $sigOk = $sigOk && str_contains($compact, 'function' . $normalizedSignature);
        $docSig = preg_replace('/\s+/', '', $service);
        $sigOk = $sigOk && str_contains($docSig, $normalizedSignature);
    }
    $check('internal method names and parameter/return signatures match source', $sigOk);
    $check('documented projection keys match returned model',
        preg_match("/'partnerId'.*?'dispatchAuthorized'=>false/s", $accounts) === 1 &&
        str_contains($service, 'partnerId`, `handle`, `displayName`, `status`, `eligibility`, `availability`, `serviceAreas`, `capabilities`, `version`, `createdEpoch`, `updatedEpoch`, `operationallyEligible`, `simulationOnly`, and `dispatchAuthorized`'));

    $check('synthetic handle, label, area and capability bounds match source',
        str_contains($accounts, 'synthetic-partner-') && str_contains($accounts, '^Synthetic ') &&
        str_contains($accounts, 'count($areas)>8') && str_contains($accounts, 'count($capabilities)>2') &&
        str_contains($accounts, 'synthetic-area-') && str_contains($accounts, "['synthetic-foot','synthetic-cycle']") &&
        str_contains($service, 'at most eight') && str_contains($service, 'at most two'));
    $check('ownership revalidation and revision-lock behavior documented',
        str_contains($accounts, 'currentPartnerId()!==$id') && str_contains($accounts, 'FOR UPDATE') &&
        str_contains($accounts, "'Partner revision conflict.'") &&
        str_contains($accounts, 'new MerchantProvisioner($this->db,$platform))->administer') &&
        str_contains($service, 'before the transaction, under the row lock, and again before commit') &&
        str_contains($service, 'platform-admin authorization via `MerchantProvisioner::administer`'));
    $check('audit tables, actor relationships, immutability and rollback documented',
        str_contains($migration, 'CREATE TABLE delivery_partner_accounts') &&
        str_contains($migration, 'CREATE TABLE delivery_partner_events') &&
        str_contains($migration, 'partner_event_no_update') && str_contains($migration, 'partner_event_no_delete') &&
        str_contains($accounts, 'INSERT INTO delivery_partner_events') &&
        str_contains($architecture, 'audit insert rolls back') && str_contains($privacy, 'same transaction'));
    $check('migration 038 and schema additions documented accurately',
        preg_match_all('/CREATE\s+TABLE\s+/i', $migration) === 2 &&
        str_contains($migration, 'CREATE TABLE delivery_partner_accounts') &&
        str_contains($migration, 'CREATE TABLE delivery_partner_events') &&
        !preg_match('/CREATE TABLE\s+(delivery_assignment|delivery_dispatch|delivery_request)/i', $migration) &&
        str_contains($architecture, 'migration 038') && str_contains($service, 'migration 038'));

    $fields = ['customerOrderId', 'merchantId', 'outletId', 'restaurantOrderId'];
    $fieldOk = true;
    foreach ($fields as $field) { $fieldOk = $fieldOk && str_contains($request, "'$field'") && str_contains($dispatch, $field); }
    $check('future request field set matches syntax-only source contract',
        $fieldOk && str_contains($request, "return false") && str_contains($request, "'UNVERIFIED_REFERENCE'") &&
        str_contains($dispatch, 'always returns `false`') && str_contains($dispatch, 'does not verify those identifiers'));
    $check('public auth and operational endpoints are not claimed as implemented',
        str_contains($provider, 'Trusted future server authentication port') &&
        str_contains($architecture, 'no public endpoint') &&
        str_contains($privacy, 'There are no public endpoints') &&
        str_contains($dispatch, 'No current service; future assignment service'));
    $check('environment gates and defaults match source',
        str_contains($accounts, "KIOS_DELIVERY_IDENTITY_ENABLED','0'") &&
        str_contains($env, 'KIOS_DELIVERY_IDENTITY_ENABLED=0') &&
        str_contains($env, 'KIOS_DELIVERY_DISPATCH_ENABLED=0') &&
        str_contains($env, 'KIOS_DELIVERY_REGISTRATION_ENABLED=0') &&
        str_contains($env, 'KIOS_DELIVERY_GPS_ENABLED=0'));
    $check('M07D intent, authoritative order and future dispatch authority remain distinct',
        str_contains($dispatch, 'M07D `ACCEPTED` is not authoritative merchant acceptance') &&
        str_contains($dispatch, 'Authoritative Restaurant Order') && str_contains($dispatch, 'Future Delivery Assignment'));

    $docs = $architecture . "\n" . $states . "\n" . $service . "\n" . $dispatch . "\n" . $privacy;
    $check('documentation examples are synthetic and contain no obvious secret/PII patterns',
        str_contains($service, 'synthetic-partner-demo-01') &&
        !preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $docs) &&
        !preg_match('/\b(?:sk-[A-Za-z0-9]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})\b/', $docs) &&
        !preg_match('/\b(?:\+?\d[\d ()-]{8,}\d)\b/', $docs));
    $check('privacy limitations and non-compliance disclaimer are explicit',
        str_contains($privacy, 'not a legal or regulatory compliance assessment') &&
        str_contains($privacy, 'retention/deletion rules') && str_contains($privacy, 'does not constitute a complete privacy program'));
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

$failed = 0;
foreach ($checks as [$name, $ok]) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . PHP_EOL;
    if (!$ok) { $failed++; }
}
printf("RESULT: %d/%d PASS, %d FAIL\n", count($checks) - $failed, count($checks), $failed);
exit($failed === 0 ? 0 : 1);
