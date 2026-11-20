# M07C Internal Customer Identity Contract

**Scope:** Source-derived internal PHP service contract. There are no HTTP endpoints or customer-facing authentication methods in this contract. The service is enabled only when APP_ENV is exactly demo and KIOS_CUSTOMER_IDENTITY_ENABLED is exactly 1.

Implementation authority: app/CustomerIdentity/CustomerAccounts.php, app/CustomerIdentity/CustomerIdentityProvider.php, MerchantProvisioner, Identity, and migration 036_customer_identity.sql.

## Types and shared behavior

- CustomerAccounts is constructed with PDO: new CustomerAccounts(PDO $db).
- CustomerIdentityProvider exposes currentCustomerId(): ?string. It is a trusted server-side identity port only; no concrete production adapter or route binding is implemented.
- Platform mutations accept the existing MerchantFoundation\AuthenticatedUserProvider. The implementation forwards that provider to MerchantProvisioner::administer(), which performs native platform-administrator authorization.
- Customer-owned methods accept CustomerIdentityProvider, not AuthenticatedUserProvider.
- UUID arguments are checked by Identity::validate(): canonical lowercase UUID-v4 shape.
- Each successful operation returns an associative account projection with exactly these possible keys: customer_id, account_handle, display_name, status, contact_state, version. Database drivers may represent version as an integer or numeric string.
- The service owns its transactions. It rejects calls while the PDO connection already has an active transaction. Customer-owned methods use READ COMMITTED, lock the account row, check ownership again in-transaction, and roll back on any thrown error.
- Errors are PHP exceptions, not HTTP status responses. Callers must not expose raw exception text as a public response.

## Operations

### create(AuthenticatedUserProvider $platform, string $handle, string $name): array

Creates a synthetic pending account. Handle is trimmed and lowercased before validation and storage; it must match ^synthetic-[a-z0-9][a-z0-9_-]{2,53}$. Display name must match ^Synthetic [A-Za-z0-9 ._-]{1,80}$. UUID is server-generated. Initial status is PENDING, contact_state is UNVERIFIED, version is 1.

Authorization: current native platform administrator, checked by MerchantProvisioner under its transaction locks.

Success: the minimized account projection.

Errors: DomainException if disabled or APP_ENV is not demo; LogicException if a caller transaction is active; InvalidArgumentException for invalid synthetic handle/name; DomainException for missing platform-administrator authorization; database exception on duplicate normalized handle or persistence failure. A failure does not return an account.

### transition(AuthenticatedUserProvider $platform, string $id, int $expected, string $next): array

Applies one allowed platform-authorized lifecycle transition using the expected account version and row lock.

Allowed transitions:
- PENDING to ACTIVE or DEACTIVATED
- ACTIVE to SUSPENDED or DEACTIVATED
- SUSPENDED to ACTIVE or DEACTIVATED
- DEACTIVATED has no outgoing transition

Success: updated minimized projection with version incremented once.

Errors: InvalidArgumentException for invalid UUID; DomainException for missing record, unauthorized administrator, disallowed transition, or stale version; DomainException if disabled or APP_ENV is not demo; LogicException if a caller transaction is active; database exception for persistence failure. State and audit writes are transactional.

### profile(CustomerIdentityProvider $auth, string $id): array

Reads the caller's own minimized profile and status.

Authorization: non-null currentCustomerId must exactly equal id, both before and during the service-owned transaction. PENDING and SUSPENDED records are readable by their owner; DEACTIVATED is denied.

Success: minimized projection.

Errors: InvalidArgumentException for invalid UUID; DomainException for ownership mismatch, null identity, missing/deactivated account, or disabled/wrong environment; LogicException if a caller transaction is active. No record lookup for another customer is returned.

### updateProfile(CustomerIdentityProvider $auth, string $id, int $expected, string $name): array

Updates only display_name for the caller's own ACTIVE account at the expected version. The same synthetic display-name pattern applies. The operation increments account version and records an audit event atomically.

Success: minimized projection.

Errors: InvalidArgumentException for invalid UUID or name; DomainException for ownership mismatch, non-ACTIVE state, or version conflict; DomainException if disabled or APP_ENV is not demo; LogicException if a caller transaction is active; persistence exceptions on write/audit failure, with rollback.

### consent(CustomerIdentityProvider $auth, string $id, int $expected, string $purpose, string $policy, bool $granted): array

For the caller's own ACTIVE account, appends an explicit consent grant or withdrawal. Purpose must be terms, privacy, or marketing. Policy version must match ^[a-zA-Z0-9._-]{1,32}$. The expected account version must match. Account version increments once; one immutable customer_consents row and one immutable security event are inserted in the same transaction.

Success: updated minimized account projection. The consent row itself is not returned.

Errors: InvalidArgumentException for invalid UUID, purpose, or policy version; DomainException for ownership mismatch, non-ACTIVE state, or version conflict; DomainException if disabled or APP_ENV is not demo; LogicException if a caller transaction is active; persistence exceptions on write/audit failure, with rollback.

## Account data and audit records

Migration 036 defines:
- customer_accounts: UUID primary key; unique canonical synthetic handle; display name; PENDING/ACTIVE/SUSPENDED/DEACTIVATED state; UNVERIFIED-only contact state; account version; created and updated epoch seconds.
- customer_consents: opaque ID; account foreign key with RESTRICT; purpose; policy version; granted flag; account version; creation epoch. It has a unique (customer_id, account_version) key and immutable UPDATE/DELETE triggers.
- customer_security_events: opaque ID; account foreign key with RESTRICT; actor_domain PLATFORM or CUSTOMER; actor_id; action_code; account_version; creation epoch. Immutable UPDATE/DELETE triggers apply.

Successful mutations add events CREATE, STATE_<next>, PROFILE, or CONSENT with actor domain, actor ID, target customer ID and resulting version. Event timestamps are supplied as Unix epoch seconds by PHP time(). The event does not store display name, contact data, credentials, consent text, or a request payload. The service inserts the event in the same transaction as the mutation; audit failure rolls back the mutation.

## Non-contracts

There is no customer login, password, token, session issuer, contact verification, recovery, public API route, customer search/list/export, order-history method, or delivery identity operation. Do not infer such behavior from this internal service contract.




## Source-derived call example

This is the call sequence and argument shape exposed by the implemented methods. The platform provider must already be a valid native platform administrator. The customer-provider variable below is a placeholder for a future trusted server-side principal; no concrete customer provider is implemented.

    $pending = $accounts->create($platformProvider, 'synthetic-demo-customer', 'Synthetic Demo');
    $active = $accounts->transition($platformProvider, $pending['customer_id'], 1, 'ACTIVE');
    $profile = $accounts->profile($trustedCustomerPrincipal, $active['customer_id']);
    $updated = $accounts->updateProfile(
        $trustedCustomerPrincipal,
        $active['customer_id'],
        $active['version'],
        'Synthetic Demo Updated'
    );
    $afterConsent = $accounts->consent(
        $trustedCustomerPrincipal,
        $active['customer_id'],
        $updated['version'],
        'marketing',
        'policy-2026-01',
        true
    );

The example uses synthetic values only. PHP argument-type mismatches raise TypeError before a method body runs. Consent history is persisted but is not returned by these methods.
