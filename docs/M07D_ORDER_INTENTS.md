# M07D internal synthetic order intents

Default disabled: APP_ENV must be demo; KIOS_CUSTOMER_ORDER_INTENTS_ENABLED and the existing KIOS_CUSTOMER_IDENTITY_ENABLED must be 1. Public ordering, registration, payments and physical printing flags must remain 0. No HTTP route, public identity provider, session issuer or customer API is added.

## Authority and data

OrderIntents requires both a trusted CustomerIdentityProvider and a legitimate native merchant AuthenticatedUserProvider. This dual authority is for internal synthetic rehearsal only. The customer must own the selected account; the operator must have current ReadOrders authority in the selected merchant/outlet. A customer UUID or a merchant session alone is never sufficient. The implementation reuses CustomerAccounts.profile before its own transaction and locks/rechecks the customer row and provider identity inside. Native staff, membership, outlet mapping and entitlements are also rechecked under locks. The service owns READ COMMITTED transactions.

The service reuses the same MenuRepository customer eligibility path used by M07B/TenantRestaurant; it does not borrow merchant identity as customer authentication. Selection is exact productId, variantId, quantity, productVersion and variantVersion integers. A draft contains 1–50 unique serving selections, quantity 1–99. Prices are never request fields. Native ProductVariant/Money objects calculate integer paise with overflow checks. Product, variant, category and station rows are locked; availability and routing eligibility are read through the existing native menu. A fingerprint binds the selected catalogue revisions and price details. Draft snapshots retain names, units, serving quantities, unit prices and line totals.

Migration 037 adds only customer_order_intents, customer_order_intent_lines and customer_order_intent_events. Composite restrictive foreign keys bind merchant/outlet/legacy mapping, each product/serving combination and intent audit attribution. Intent snapshots, ownership, scope, original timestamps and totals cannot be changed; serving rows and audit events reject UPDATE/DELETE. No existing business table or migration definition is altered. Account IDs and intent IDs are canonical server-generated UUID-v4 identifiers. Timestamps are server epoch seconds; a trusted clock seam is available only for deterministic internal tests.

## Implemented service contract

- create(customer, merchant, outlet, accountVersion, requestId, lines): creates one immutable DRAFT and CREATE audit receipt. A new reviewed draft replaces a stale selection; editing snapshots in place is not supported.
- read(customer, merchant, outlet, intentId): returns only the owned intent projection and selected product snapshot; no customer directory, other customer's records, session, credential or payment details.
- transition(customer, merchant, outlet, accountVersion, requestId, intentId, expectedVersion, next): validates the state graph, deadline, account version, current identities and scoped operator. Submission and simulated acceptance re-evaluate native eligibility and require unchanged snapshot/revisions.

All methods are internal PHP calls, not endpoints. Request IDs are 32 lowercase hexadecimal characters. The immutable event table enforces global request uniqueness. An exact retry by the same actor/customer/scope/payload returns the original receipt without another mutation; conflicting key reuse is denied. A new request with a stale intent/account version fails. Customer-row locking serializes concurrent requests for that customer; intent rows and catalogue rows are locked as needed. Audit insertion failure rolls back intent creation/state changes and serving references. Expected-version receipts are historical results, not an implicit refresh of the latest record.

## Simulation lifecycle

| State | Allowed next states |
| --- | --- |
| DRAFT | SUBMITTED, CANCELLED, EXPIRED |
| SUBMITTED | PENDING_MERCHANT_ACCEPTANCE, CANCELLED, EXPIRED |
| PENDING_MERCHANT_ACCEPTANCE | ACCEPTED, REJECTED, CANCELLED, EXPIRED |
| ACCEPTED / REJECTED / CANCELLED / EXPIRED | none |

Every transition is a simulation jointly authorized by the current customer principal and internal outlet operator. ACCEPTED never means a real merchant transaction. There is no background timer: intents have a fixed server-generated 30-minute deadline; at/after it, nonterminal records may only transition explicitly to EXPIRED. Before the deadline EXPIRED is rejected. ACTIVE customer state is required for creation and forward processing. Suspended customers may inspect or cancel/expire their own nonterminal intent with current account version and internal operator authority; they cannot submit or accept. DEACTIVATED customers are denied all intent access. No operation reactivates an account.

Cancellation, rejection and expiry retain historical prices and audit receipts even when products later become unavailable. New drafts and forward transitions cannot use unavailable/archived products, inactive serving options, category/station restrictions or stale prices. Product removal is restricted by historical reference foreign keys; archival is the normal non-destructive catalogue operation.

## Future restaurant handoff: not implemented

StaffPos and OrderRepository remain owners of real restaurant ordering, authoritative operational snapshots and KOT creation. TenantOperations owns kitchen authorization/transitions; existing bill, inventory and usage services remain authoritative. OrderIntents calls none of those mutating operations. No operational order ID, payment authorization, kitchen ticket, stock deduction, usage charge, bill or delivery assignment is generated here.

A future separately authorized handoff must authenticate a real customer through a qualified customer session, resolve a merchant/outlet admission policy without granting staff authority to customers, recheck shop acceptance and subscription/RBAC policy, revalidate catalogue availability/prices, collect explicit customer acceptance of any changed quote and required payment rules, and map the customer intent to exactly one operational order with durable idempotency and an atomic or recoverable outbox boundary. It must reuse OrderRepository rather than duplicate pricing/order/KOT rules. Accepted simulation receipts must not be converted silently into operational orders. Refund, settlement, delivery, privacy/retention and support ownership need separate contracts.

## Qualification and limits

Run only synthetic tests in the established hardened guest with read-only source/tools, networking and clipboard disabled, Protected Client enabled, and an isolated disposable database. Exercise cross-customer/merchant/outlet denial, invalid/stale state, price injection, unavailable products, duplicate creation, version races, audit rollback, immutable history and operational table preservation. Full recovery must include populated intent tables. Source presence is not evidence of test execution.

The trusted PHP providers/clock are not proof against arbitrary malicious server code or a database administrator. No public customer authentication, marketplace transport, legal privacy certification, real merchant acceptance, ordering, payment or delivery capability is claimed. Shift 02 and any hosted/GitHub release require separate authorization.
