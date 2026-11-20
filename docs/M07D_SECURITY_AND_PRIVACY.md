# M07D Security and Privacy Notes

## Implemented controls

- **Customer ownership:** Every operation compares the requested customer UUID with the trusted `CustomerIdentityProvider`, uses `CustomerAccounts::profile()`, then locks and rechecks account state and identity inside the intent transaction.
- **Customer isolation:** Reads are scoped by customer, merchant, outlet, and intent ID. An intent outside that composite scope is unavailable. Deactivated accounts cannot read or mutate retained intents.
- **Merchant/outlet isolation:** The operator is resolved by existing native tenant identity and must hold active `ReadOrders` permission for the selected merchant/outlet, plus the `menu:read` entitlement. Active staff, memberships, outlet mapping, and provider identity are rechecked under lock.
- **Identity separation:** The customer provider and merchant staff provider are distinct required interfaces. A merchant/platform user or submitted UUID does not prove customer identity. The provider interfaces are trusted internal ports, not a public authentication system.
- **Least data in the projection:** The service returns scoped intent data and the selected product/serving snapshot only. It does not return passwords, tokens, session data, payment details, customer contact data, or a customer directory. Internal audit rows retain the customer/scope and staff actor IDs needed for accountability.
- **Mutation integrity:** Request keys, payload hashes, expected versions, transaction locks, restrictive foreign keys, and immutable audit receipts protect writes from replay, stale updates, cross-scope references, and partial audit failure.
- **Sensitive side effects absent:** No live order, POS/KOT, inventory, billing, usage, payment, refund, settlement, notification, or delivery operation is invoked.

## Retention and privacy limitations

Migration 037 intentionally prevents deletion of an intent and update/deletion of line and event history. This preserves referential and audit integrity. M07D does not define an account-erasure workflow, retention schedule, legal basis, data-subject-request process, privacy notice, or privacy-law compliance certification. Those require separate policy and engineering decisions. The current records are for synthetic qualification only.

The application code and trusted provider interfaces are not a defense against arbitrary malicious server code, privileged database administrators, host compromise, or an incorrectly implemented future provider. A future public customer experience requires a separately qualified server-owned session mechanism, CSRF/rate-limit/abuse controls, account recovery policy, privacy review, and independently tested tenant isolation.

## Configuration boundary

The service remains inactive unless the private demo flags are deliberately set. Public customer registration, public authentication, public ordering, real payment processing, physical printing, and delivery dispatch remain disabled. Shift 02 changes documentation and static checks only; it must not change environment configuration or enable a route.

## Verification claims

Shift 01's hardened-guest evidence covers synthetic customer and merchant/outlet isolation, identity changes, revoked membership, account-state/version checks, idempotency, stale revisions, audit rollback, immutable history, and preservation of unrelated operational tables. Shift 02 adds static documentation-to-source checks; it does not claim to replace that dynamic security suite. See the Shift 01 report for exact executed test totals and guest evidence paths.