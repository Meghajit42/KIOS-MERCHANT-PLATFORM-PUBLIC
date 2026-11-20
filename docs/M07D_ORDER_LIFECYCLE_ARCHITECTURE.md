# M07D Customer Order-Intent Lifecycle Architecture

## Status and scope

This document describes the internal, synthetic customer-order-intent foundation in the qualified M07D Shift 01 source. It is not a public ordering specification. There is no customer-facing HTTP route, customer login, session issuer, API token issuer, restaurant acceptance endpoint, or live order handoff in this implementation.

The entry point is `Kios\CustomerOrders\OrderIntents`, which uses the existing customer-account, tenant-identity, entitlement, catalogue, money, and database services. The implementation is default-disabled and permits only demo simulation when `APP_ENV=demo`, `KIOS_CUSTOMER_ORDER_INTENTS_ENABLED=1`, and the existing customer-identity foundation is enabled. Public ordering, public registration, payment, and physical-printing flags must remain off. These flags do not create a public capability.

## Principals and ownership

Every service operation is scoped to a customer UUID, merchant UUID, and outlet UUID. The customer ID must match the trusted `CustomerIdentityProvider`; it is not accepted as proof of identity by itself. The second required principal is an existing `AuthenticatedUserProvider` resolved through native `TenantContext` and `PdoIdentityStore`. That staff user must have current `ReadOrders` permission for the merchant/outlet and the `menu:read` entitlement. Merchant and platform identities are not customer identities.

`CustomerAccounts::profile()` performs the existing owned-account check before the intent service opens its own transaction. In the intent transaction, the account row and native authorization/membership/outlet mapping are rechecked under locks, and both provider identities are compared again before commit. The service owns a MariaDB/MySQL `READ COMMITTED` transaction and rejects a caller-owned transaction. Deactivated customer accounts cannot read or mutate intents. Creation and forward processing require an `ACTIVE` account; suspension permits read and cancellation/expiry only, subject to current account version and operator authorization.

## Identifiers and catalogue references

Intent IDs are generated server-side using the existing identity generator and are canonical UUIDs. The intent stores customer, merchant, and outlet UUIDs plus the legacy numeric outlet mapping used by the existing catalogue schema. Foreign keys bind those values together.

Each selected row is an exact `(productId, variantId)` serving option. Input also supplies `quantity`, `productVersion`, and `variantVersion`; it does not supply price, product name, unit, or totals. A request contains one to fifty unique product/variant pairs. Values must be PHP integers in the positive signed-32-bit range; quantity is further limited to 1–99. Duplicate product/variant pairs are rejected and accepted rows are sorted by product and variant ID before hashing and processing.

The intent service locks the referenced product, variant, category, and preparation-station rows and requires the supplied revisions to match. It then uses `MenuRepository::customerProductsForCategory()`, the same customer-eligibility path used by the existing catalogue integration. The outlet, category, product, routing approval, active station, and active serving-option eligibility are therefore evaluated by existing KIOS rules. A product that is unavailable, archived/inactive, not routing-approved, outside customer-eligible station/category rules, or has an inactive serving option cannot be used to create or advance a quote.

## Price snapshot

No client-supplied price is accepted. Price comes from the eligible native `ProductVariant` and `Money` values in the existing catalogue. Integer paise multiplication and addition use the domain overflow checks. The immutable snapshot records the selected references and revisions, product name, serving label, unit, serving quantity value, unit price, line total, and a SHA-256 catalogue fingerprint. The intent also stores the authoritative `total_paise`.

Before `SUBMITTED`, `PENDING_MERCHANT_ACCEPTANCE`, or simulated `ACCEPTED`, the service re-reads catalogue eligibility and compares the current quote/snapshot and total with the stored values. A changed price, revision, or relevant catalogue snapshot requires a new reviewed draft; it does not silently reprice an existing intent. Terminal outcomes preserve their historical snapshot even when the product later becomes unavailable.

## Internal persistence

Migration `037_customer_order_intents.sql` adds three append-oriented simulation tables:

- `customer_order_intents`: scoped owner, status/version, immutable JSON snapshot, paise total, creation/expiry/update epochs, and legacy outlet reference.
- `customer_order_intent_lines`: immutable product/variant/quantity selection with restrictive references to the intent and native catalogue rows.
- `customer_order_intent_events`: one immutable result receipt per request key and intent version, including customer/merchant/outlet attribution, native staff actor, action, payload hash, result projection, epoch seconds, and `SIMULATION` mode.

Composite and restrictive foreign keys enforce intent scope and audit attribution. Database triggers reject event and selection updates/deletes, intent deletion, and changes to ownership, scope, price snapshot, total, original creation epoch, or expiry epoch. Status/version/update epoch remain the service-controlled lifecycle fields. Audit insertion is inside the same transaction as intent creation or transition; an audit failure rolls back that operation.

Timestamps are Unix epoch seconds generated by the server clock seam used by deterministic tests. Epoch values are UTC-based; this is not a client-provided local time.

## Lifecycle and expiry

The actual state graph is:

- `DRAFT` → `SUBMITTED`, `CANCELLED`, or `EXPIRED`
- `SUBMITTED` → `PENDING_MERCHANT_ACCEPTANCE`, `CANCELLED`, or `EXPIRED`
- `PENDING_MERCHANT_ACCEPTANCE` → `ACCEPTED`, `REJECTED`, `CANCELLED`, or `EXPIRED`
- `ACCEPTED`, `REJECTED`, `CANCELLED`, and `EXPIRED` are terminal.

A fixed 30-minute server-generated deadline is stored at creation. There is no background expiry scheduler. Before the deadline, an explicit `EXPIRED` transition is rejected. At or after the deadline, only explicit `EXPIRED` is permitted; other transitions are rejected. Expiry is therefore recorded by an authorized service call, not inferred from elapsed time during a read.

`ACCEPTED` means accepted in this synthetic simulation only. It is not a merchant commitment or operational restaurant order.

## Concurrency and idempotency

Each create/transition mutation supplies a 32-character lowercase hexadecimal request ID. A canonical payload hash binds operation, scoped parties, account version, selected rows or expected intent version/next state. The global request primary key and unique `(intent_id, intent_version)` constrain duplicate receipts. A retry with the same request key and exact actor, customer, merchant, outlet, and payload returns its original receipt without a second mutation. Reuse with changed scope/actor/payload is rejected.

Customer rows are locked to serialize a customer's mutations; intent, membership, mapping, and selected catalogue rows are locked as needed. `expectedVersion` and `accountVersion` provide stale-write rejection. Conflicting distinct requests do not silently replay a newer state. The service and database transaction roll back state and receipt together on failures.

## Privacy and operational boundary

The returned projection contains intent and selected product/serving snapshots needed for the simulation. It does not expose authentication material, session data, passwords, payment data, or a customer directory. Audit records identify the customer, merchant, outlet, and internal staff actor necessary for accountability. Retention/deletion schedules and privacy-law compliance are not established by this feature; migration constraints intentionally retain referenced historical intents and receipts.

The intent service does not invoke `StaffPos`, `OrderRepository`, `TenantOperations`, KOT creation, inventory deduction, usage metering, billing, payment, refund, settlement, or delivery dispatch. Existing restaurant services remain authoritative for actual operations. No behavior in this document should be interpreted as enabling public customer ordering.