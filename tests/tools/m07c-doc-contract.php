<?php
declare(strict_types=1);

/** Static Shift 02 documentation contract check. No application bootstrap, DB, or network access. */
$root = $argv[1] ?? dirname(__DIR__, 2);
$paths = [
    'architecture' => $root . '/docs/M07C_CUSTOMER_IDENTITY_ARCHITECTURE.md',
    'contract' => $root . '/docs/M07C_INTERNAL_IDENTITY_CONTRACT.md',
    'integration' => $root . '/docs/M07C_FUTURE_CUSTOMER_APP_INTEGRATION.md',
    'accounts' => $root . '/app/CustomerIdentity/CustomerAccounts.php',
    'provider' => $root . '/app/CustomerIdentity/CustomerIdentityProvider.php',
    'migration' => $root . '/database/migrations/036_customer_identity.sql',
];
$files = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required $name file\n");
        exit(2);
    }
    $files[$name] = file_get_contents($path);
}
$checks = [];
$check = static function (string $name, bool $pass) use (&$checks): void {
    $checks[$name] = $pass ? 'PASS' : 'FAIL';
};
$a = $files['accounts'];
$m = $files['migration'];
$docs = implode("\n", [$files['architecture'], $files['contract'], $files['integration']]);

$check('Requested documents exist', true);
$check('Documented service methods and signatures match source',
    preg_match_all('/public function (create|transition|profile|updateProfile|consent)\s*\(/', $a) === 5
    && str_contains($files['contract'], 'create(AuthenticatedUserProvider $platform, string $handle, string $name): array')
    && str_contains($files['contract'], 'transition(AuthenticatedUserProvider $platform, string $id, int $expected, string $next): array')
    && str_contains($files['contract'], 'profile(CustomerIdentityProvider $auth, string $id): array')
    && str_contains($files['contract'], 'updateProfile(CustomerIdentityProvider $auth, string $id, int $expected, string $name): array')
    && str_contains($files['contract'], 'consent(CustomerIdentityProvider $auth, string $id, int $expected, string $purpose, string $policy, bool $granted): array'));
$check('Provider is documented as a trusted non-authenticating port',
    str_contains($files['provider'], 'interface CustomerIdentityProvider')
    && str_contains($files['provider'], 'function currentCustomerId():?string;')
    && str_contains($files['integration'], 'not a credential verifier'));
$check('States and transitions match code and migration',
    str_contains($m, "status ENUM('PENDING','ACTIVE','SUSPENDED','DEACTIVATED')")
    && str_contains($a, "'PENDING'=>['ACTIVE','DEACTIVATED']")
    && str_contains($a, "'ACTIVE'=>['SUSPENDED','DEACTIVATED']")
    && str_contains($a, "'SUSPENDED'=>['ACTIVE','DEACTIVATED']")
    && str_contains($files['contract'], 'DEACTIVATED has no outgoing transition'));
$projection = ['customer_id','account_handle','display_name','status','contact_state','version'];
$projectionMatches = true;
foreach ($projection as $field) {
    $projectionMatches = $projectionMatches
        && str_contains($a, "'" . $field . "'")
        && str_contains($files['contract'], $field)
        && str_contains($files['architecture'], $field);
}
$check('Minimized projection fields match', $projectionMatches);
$check('Consent purposes, policy validation and immutable history match',
    str_contains($a, "['terms','privacy','marketing']")
    && str_contains($a, "'/^[a-zA-Z0-9._-]{1,32}$/D'")
    && str_contains($m, 'customer_consent_immutable_update')
    && str_contains($m, 'customer_consent_immutable_delete')
    && str_contains($files['contract'], 'terms, privacy, or marketing'));
$check('Audit actions, attribution and immutable history match',
    str_contains($a, "'CREATE'")
    && str_contains($a, "'STATE_'")
    && str_contains($a, "'PROFILE'")
    && str_contains($a, "'CONSENT'")
    && str_contains($m, 'customer_event_immutable_update')
    && str_contains($m, 'customer_event_immutable_delete')
    && str_contains($files['architecture'], 'roll back atomically'));
$check('Migration tables and restrictive relationships match',
    substr_count($m, 'CREATE TABLE ') === 3
    && substr_count($m, 'ON DELETE RESTRICT') === 2
    && str_contains($files['architecture'], 'ON DELETE RESTRICT'));
$check('Authorization and account-state rules match',
    str_contains($a, 'MerchantProvisioner')
    && str_contains($a, "status']!=='ACTIVE'")
    && str_contains($files['contract'], 'native platform administrator')
    && str_contains($files['contract'], 'PENDING and SUSPENDED records are readable')
    && str_contains($files['contract'], 'DEACTIVATED is denied'));
$check('Unimplemented customer authentication and recovery are not claimed',
    stripos($docs, 'There is no customer login') !== false
    && stripos($docs, 'No public registration') !== false
    && stripos($docs, 'No customer session issuer exists') !== false
    && stripos($docs, 'Recovery is design-only') !== false);
$publicFiles = glob($root . '/public/*.php') ?: [];
$publicText = implode("\n", array_map('file_get_contents', $publicFiles));
$check('No customer identity service binding in public PHP entry files',
    !preg_match('/CustomerAccounts|CustomerIdentityProvider/', $publicText));
$check('No common private-key or token literals in new documentation',
    !preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|(?:gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{20,})/', $docs));
$passed = count(array_filter($checks, static fn($result) => $result === 'PASS'));
$result = ['check_type'=>'static-documentation-contract-only','passed'=>$passed,'failed'=>count($checks)-$passed,'checks'=>$checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($passed === count($checks) ? 0 : 1);
