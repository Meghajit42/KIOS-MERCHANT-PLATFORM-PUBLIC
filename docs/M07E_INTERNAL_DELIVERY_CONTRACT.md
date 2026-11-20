# M07E Internal Delivery Service Contract

These are internal PHP service methods, not HTTP routes or a public API. They operate only in the isolated synthetic demo when the service guard is satisfied.

## Common execution boundary

`PartnerAccounts(PDO $db)` requires the PDO driver name `mysql`, exception error mode, and native prepares. Each service call requires `APP_ENV=demo`, `KIOS_DELIVERY_IDENTITY_ENABLED=1`, all public ordering/registration, payments, physical printing, dispatch, delivery registration, and GPS flags explicitly `0`, and no ambient transaction. The service owns its transaction. Platform administration uses the existing `MerchantProvisioner::administer` path; partner-owned methods validate the trusted `PartnerIdentityProvider` before the transaction, under the row lock, and again before commit. Timestamps are server UTC epoch seconds.

## Method signatures

| Method | Exact input signature | Authorization | Operation |
| --- | --- | --- | --- |
| `create` | `create(AuthenticatedUserProvider $platform, string $handle, string $name): array` | Existing platform-admin authorization via `MerchantProvisioner::administer` | Create PENDING/INELIGIBLE/OFFLINE account at revision 1 and append CREATE event. |
| `transition` | `transition(AuthenticatedUserProvider $platform, string $id, int $expected, string $next): array` | Existing platform-admin authorization | Apply only the allowed account transitions; clear eligibility and availability. |
| `eligibility` | `eligibility(AuthenticatedUserProvider $platform, string $id, int $expected, string $next): array` | Existing platform-admin authorization; ACTIVE account required | Set ELIGIBLE or INELIGIBLE and force OFFLINE. |
| `profile` | `profile(PartnerIdentityProvider $auth, string $id): array` | Same-partner ownership; unavailable after deactivation | Read the minimized owned projection. |
| `updateProfile` | `updateProfile(PartnerIdentityProvider $auth, string $id, int $expected, string $name): array` | Same-partner ownership; ACTIVE required | Change constrained synthetic display name. |
| `configure` | `configure(PartnerIdentityProvider $auth, string $id, int $expected, array $areas, array $capabilities): array` | Same-partner ownership; ACTIVE required | Replace bounded synthetic coverage/capabilities and force OFFLINE. |
| `availability` | `availability(PartnerIdentityProvider $auth, string $id, int $expected, string $next): array` | Same-partner ownership; ACTIVE required | Set OFFLINE, AVAILABLE, or TEMPORARILY_UNAVAILABLE subject to readiness preconditions. |

All successful methods return the same account projection with keys: `partnerId`, `handle`, `displayName`, `status`, `eligibility`, `availability`, `serviceAreas`, `capabilities`, `version`, `createdEpoch`, `updatedEpoch`, `operationallyEligible`, `simulationOnly`, and `dispatchAuthorized`. The latter two are respectively `true` and `false` for this synthetic service.

## Inputs and validation

- `id` must pass the existing UUID validator and resolve to exactly one partner.
- `handle` is trimmed, lowercased and must match `synthetic-partner-` followed by the bounded lowercase handle suffix; the database enforces uniqueness.
- `name` must match `Synthetic ` plus 1–80 allowed ASCII letters, digits, spaces, dots, underscores, or hyphens.
- `expected` must be at least 1 and exactly equal the locked row revision.
- `next` for account status must be one of the allowed lifecycle edges; eligibility is only `ELIGIBLE` or `INELIGIBLE`; availability is only `OFFLINE`, `AVAILABLE`, or `TEMPORARILY_UNAVAILABLE`.
- `areas` must be a list of at most eight unique `synthetic-area-*` values. `capabilities` must be a list of at most two unique values, each `synthetic-foot` or `synthetic-cycle`.
- No real contact, address, identity-document, GPS, credential, assignment, or customer data is accepted by these methods.

## Return, errors and effects

Invalid input raises `InvalidArgumentException`; failed authorization, missing/unavailable identity, stale revision, invalid state transition, or a violated readiness precondition raises `DomainException`; unsupported database/transaction configuration raises `LogicException`; PDO/database failures propagate. Errors are not converted to success responses by this service.

Every successful mutation increments the revision and appends one attributed immutable `delivery_partner_events` row in the same transaction. A failed event write rolls back the account write. Locking plus the expected revision serializes competing writes and rejects stale requests. Duplicate handle creation fails atomically. There is no idempotency receipt table; callers must not treat retries as guaranteed to return the original response.

## Database dependencies and non-contracts

The service depends on migration 038 tables and native `merchant_staff` references for platform audit attribution. It calls existing platform authorization/transaction machinery. It has no public route, public partner authentication method, merchant/customer endpoint, dispatch queue, delivery assignment, customer lookup, payment, GPS, or order-state mutation. `FutureDeliveryRequest` is a separate syntax-only value object and is not a service method or persistence contract.

## Synthetic example

```php
$accounts->create($trustedPlatformProvider, 'synthetic-partner-demo-01', 'Synthetic Demo Courier');
```

The example assumes the existing trusted platform provider and isolated demo setup; the handle and label are synthetic. It does not create a login, activate a delivery, or authorize dispatch.
