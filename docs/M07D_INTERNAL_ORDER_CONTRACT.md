# M07D Internal Order-Intent Service Contract

## Service and boundary

`Kios\CustomerOrders\OrderIntents` is an internal PHP service backed by MariaDB/MySQL. It is not registered as an HTTP endpoint. Its database connection must not already be in a transaction: the service sets `READ COMMITTED`, begins and commits/rolls back its own transaction. It is available only in the protected demo simulation configuration; public ordering, registration, payments, and physical printing remain disabled.

The constructor is:

```php
new OrderIntents(PDO $db, CustomerIdentityProvider $customer,
                 AuthenticatedUserProvider $operator, ?Closure $clock = null)
```

The two provider interfaces are trusted server-side ports. Neither a submitted customer UUID nor a merchant login alone establishes a customer identity. The optional clock seam supports deterministic internal tests; it is not client-controlled.

## Public PHP methods (internal calls only)

### `create(string $c, string $m, string $o, int $accountVersion, string $request, array $lines): array`

Creates one version-1 `DRAFT` and immutable `CREATE` event receipt. `$c`, `$m`, and `$o` are the customer, merchant, and outlet UUIDs. `$accountVersion` must match the currently owned account version. `$request` must be 32 lowercase hexadecimal characters. `$lines` must be a nonempty list with 1–50 unique selections. Every selection must contain exactly these integer fields:

```php
[
  'productId' => 101,
  'variantId' => 4,
  'quantity' => 2,
  'productVersion' => 3,
  'variantVersion' => 1,
]
```

The example identifiers are synthetic and illustrative. Each value is a positive integer no greater than 2,147,483,647; quantity is 1–99. Price, line total, product name, unit, serving label, and category are not accepted as input. Duplicate `(productId, variantId)` pairs are rejected. Selection rows are canonically sorted before payload hashing and quoting.

### `read(string $c, string $m, string $o, string $id): array`

Returns the scoped intent projection only if the current customer identity owns `$c` and the current internal staff operator remains authorized for `$m`/`$o`. It has no request ID and does not mutate or create an audit event. It denies an unknown/out-of-scope intent and deactivated account.

### `transition(string $c, string $m, string $o, int $accountVersion, string $request, string $id, int $expected, string $next): array`

Attempts exactly one allowed transition from the stored state/version. `$expected` is the current expected intent version. `$next` must be one of the actual state names and allowed for the current state. Successful writes increment the version and append one `SIMULATION` event receipt in the same transaction.

## Return shape

All methods return an associative PHP array projection with these top-level keys:

```text
intentId, customerId, merchantId, outletId, status, version,
totalPaise, createdEpoch, expiresEpoch, simulationOnly, lines
```

`simulationOnly` is always `true`. Each line includes the validated input references/revisions/quantity plus server-resolved `name`, `serving`, `unit`, `quantityValue`, `unitPricePaise`, `lineTotalPaise`, and `catalogueFingerprint`. The line snapshot is immutable after creation. The response is not an operational restaurant order or payment object.

## Catalogue and price verification

The service resolves the UUID outlet through current active merchant/outlet/legacy mapping and current native `ReadOrders` and `menu:read` authority. It locks catalogue references and checks supplied product and variant versions. `MenuRepository::customerProductsForCategory()` determines current customer eligibility. The selected active `ProductVariant` and domain `Money` determine unit and total price in integer paise. The server does not trust a submitted amount.

Create snapshots the current eligible quote. `SUBMITTED`, `PENDING_MERCHANT_ACCEPTANCE`, and `ACCEPTED` require the current quote, revisions, line snapshot, catalogue fingerprint, and total to remain equal to the saved snapshot. A changed quote requires a new draft and a separately reviewed customer choice; this service does not edit/reprice the old draft.

## Ownership and authorization checks

Every operation checks the trusted customer provider against the supplied customer ID and uses the existing `CustomerAccounts` ownership/profile path. The same call also resolves a legitimate native staff principal for the exact merchant/outlet and requires `Permission::ReadOrders` plus the `menu:read` entitlement. Staff active state, merchant/outlet membership, current mapping, and both provider identities are rechecked in the service-owned transaction. The service does not accept platform administration as a substitute for outlet staff authority.

Account state rules: create and forward processing require `ACTIVE`; suspended accounts may read and cancel/expire their own nonterminal records with current version and operator authority; `DEACTIVATED` denies reads and mutations. A read does not require the account to be active, but all ownership and operator checks still apply.

## Idempotency and concurrency

- Mutations require a unique 32-character lowercase hexadecimal request ID.
- Canonical JSON payload hashes bind operation and scope/version/payload.
- Exact retry by the same customer, merchant, outlet, internal actor, and payload returns the original receipt.
- Different payload/scope/actor under the same key is rejected.
- Distinct stale `accountVersion` or `expected` values are rejected; the service does not silently refresh.
- Customer, intent, membership/mapping and catalogue rows are locked as applicable; intent versions advance optimistically.
- Unique database keys enforce request uniqueness and one receipt per intent version.
- Receipt insertion and intent mutation share one transaction. Audit-write failure rolls the full change back.

## Error behavior

The service throws exceptions; it does not define an HTTP error envelope.

- `InvalidArgumentException`: malformed IDs/selections/request key, invalid fields, duplicate serving selection, or bounds violation.
- `DomainException`: disabled private feature boundary, ownership/operator/entitlement failure, inactive mapping, stale account/intent version, invalid transition, expired deadline misuse, or unavailable/stale catalogue/quote.
- `LogicException`: unsupported database driver or caller-owned transaction.
- `PDOException` and other underlying failures propagate after transaction rollback where the service owns the transaction.

A duplicate exact mutation request is not an error: it returns the stored original result. A conflicting request-key reuse is a `DomainException`.

## Migration and audit storage

Migration 037 defines `customer_order_intents`, `customer_order_intent_lines`, and `customer_order_intent_events`. The event row stores request ID, intent/customer/merchant/outlet, native staff actor, action, payload hash, resulting intent version, result JSON, server epoch, and `SIMULATION` mode. Database uniqueness, restrictive foreign keys, and immutability triggers complement service checks. Epoch values are UTC-based seconds. Audit events are not edited by this service and trigger rules reject update/delete.

## Explicitly unsupported

No public endpoint, public customer authentication, client session binding, merchant approval endpoint, native order handoff, native order ID, KOT, stock change, usage/billing mutation, payment, refund, settlement, notification, delivery assignment, or background expiry worker is implemented. Do not infer these from the PHP method names or lifecycle labels.