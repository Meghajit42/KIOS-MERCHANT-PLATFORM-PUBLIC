# M07D Restaurant Integration Boundary

## Present-day boundary

M07D currently records an **internal synthetic customer order intent**. It does not accept a real customer order and does not create an **authoritative restaurant order / POS / KOT**. `OrderIntents::create()`, `read()`, and `transition()` are PHP service calls only, with no public controller or route. The `ACCEPTED` status means simulation accepted; it is not an actual merchant promise, sale, or operational order.

The authoritative restaurant path remains the existing staff/POS and order services (`StaffPos`, `OrderRepository`, and the existing kitchen/tenant operations). M07D does not call them and does not create their order, item, preparation, or payment records. Migration 037 contains only the three synthetic intent tables.

## Boundary diagram

```text
Internal synthetic rehearsal
  trusted CustomerIdentityProvider + scoped native staff operator
  -> OrderIntents + migration 037 tables
  -> DRAFT / SUBMITTED / PENDING_MERCHANT_ACCEPTANCE /
     ACCEPTED | REJECTED | CANCELLED | EXPIRED
  -> immutable SIMULATION receipts; no POS side effect

                     -- no implemented handoff --

Authoritative restaurant operations (existing)
  authorized staff/POS -> OrderRepository -> operational order snapshot
                       -> kitchen authorization / preparation / KOT
                       -> existing inventory, billing, usage and payment policies
```

## Prerequisites for any future handoff

A later, separately authorized implementation would need all of the following before converting an intent into an operational order:

1. **Qualified customer authentication and ownership.** Replace internal synthetic identity injection with a server-owned, authenticated customer session/admission mechanism. A submitted UUID or merchant staff identity cannot authenticate a customer. Define account suspension, deactivation, consent and session revocation behavior.
2. **Merchant/outlet acceptance authority.** Define who can accept a customer intent and how that authority differs from staff `ReadOrders`. The M07D dual-principal simulation permission is not an acceptance policy. Verify merchant, outlet, subscription entitlement, operating state and any order-intake controls at handoff time.
3. **Explicit quote review.** Revalidate outlet, category, product, serving option, availability, routing, price, tax/fee policy if applicable, and version immediately before acceptance. If quote changed, obtain explicit customer re-acceptance; never silently substitute a price or item.
4. **State ownership and idempotent mapping.** Define which service owns customer-facing intent states and which owns restaurant order states. Introduce a durable mapping from one accepted intent to exactly one native order and stable idempotency behavior across retries, concurrent workers, timeout and recovery.
5. **Transactional/recovery boundary.** Use one well-defined transaction where storage permits or a durable outbox/inbox/reconciliation protocol if intent and native order writes cannot be atomic. Prove rollback, duplicate suppression, partial-failure recovery and audit consistency. Simulation receipts must never be silently reinterpreted as live orders.
6. **Kitchen/KOT submission.** Call the existing `OrderRepository`/authorized restaurant operations; preserve the current station routing, preparation lifecycle and digital KOT behavior. Define when the order becomes visible to kitchens and how rejection/cancellation races are handled. Physical printing remains separately disabled unless independently authorized and qualified.
7. **Inventory policy.** Decide when stock is reserved/decremented, how concurrent acceptance is serialized, and how cancellation/rejection/expiry releases reservations. Reuse existing inventory services and protect audit history; M07D performs no stock adjustment.
8. **Billing, payments and usage.** Define the existing billing-draft/usage entitlement interaction and separate authorization for a payment gateway, settlement, refunds, taxes/fees, invoices and customer notifications. No charge, financial obligation, usage event or billing record is created by the current simulation.
9. **Failure support and privacy.** Define customer support ownership, data minimization, retention/deletion, complaint resolution, observability, rate limits and privacy obligations before any real customer flow. Current retention constraints preserve referenced synthetic audit history but are not a legal retention policy.
10. **Compatibility and deployment controls.** Version internal contracts, add backward-compatible migrations, tenant-isolation and failure-injection coverage, operational rollback and a separately approved release. Public ordering remains disabled until all authorization and acceptance gates are complete.

## Failure cases to design for later

Future handoff tests must include: a simultaneous staff acceptance and customer cancellation; price/availability change between display and acceptance; repeated request after a network timeout; native order creation success followed by intent-update failure; intent update success followed by event-write failure; worker restart with an outstanding outbox record; outlet suspension or closing during handoff; inventory contention; and a partial kitchen/KOT dispatch failure. Recovery must be deterministic and must not duplicate operational orders or financial obligations.

## Shift 02 does not implement the handoff

This document is a boundary contract and preparation aid only. No new route, identity mechanism, transaction, order mapping table, outbox, scheduler, payment integration, inventory change, KOT write, API, customer notification or merchant acceptance control is added in Shift 02. Public registration/order authentication and live ordering remain disabled. Every capability described as future requires separate implementation authorization, security qualification and deployment authorization.