# M07E Future Dispatch Integration Boundary

M07E introduces a partner identity foundation and a syntax-only reference type. It does not perform dispatch. These concepts have different owners and must remain separate:

| Concept | Current/future meaning | Authority owner |
| --- | --- | --- |
| **M07D Synthetic Customer Order Intent** | A simulated customer expression of intent with M07D's own state and history. It is not a paid/accepted restaurant order. | M07D intent services |
| **Authoritative Restaurant Order** | A future validated order accepted by an authorized merchant and tied server-side to a merchant and outlet. It does not currently arise from an automatic M07D handoff. | Existing/native restaurant order service, after a separately designed and authorized handoff |
| **Future Delivery Request** | A future persisted request linking an authoritative restaurant order to a delivery workflow. Current `FutureDeliveryRequest` is only an immutable, non-persisted, syntax-only reference whose state is `UNVERIFIED_REFERENCE`. | No current service; future scoped delivery service |
| **Future Delivery Assignment** | A future separately authorized allocation of a validated request to an eligible partner, with explicit lifecycle and immutable history. | No current service; future assignment service |

`FutureDeliveryRequest::reference` accepts exactly these fields: `customerOrderId`, `merchantId`, `outletId`, and `restaurantOrderId` (the first three are UUID strings; the last is a bounded positive integer). It does not verify those identifiers, query the database, create a request, enqueue work, or grant permission. `dispatchAuthorized()` always returns `false`. M07D `ACCEPTED` is not authoritative merchant acceptance.

## Required gates before any real dispatch capability

Every item below is future work and requires separate authorization and qualification:

1. **Merchant acceptance:** establish an authenticated, permission-checked, audited merchant acceptance action; bind it idempotently to the authoritative native restaurant order. Do not infer acceptance from a customer intent or payment flag.
2. **Authoritative order validation:** resolve the customer, merchant, outlet, and restaurant order from server-owned records; verify tenant/outlet relationships, order state, allowed fulfillment mode, and that the shop/outlet is permitted to accept work. Never trust caller-provided IDs as proof.
3. **Delivery eligibility:** calculate policy from authoritative order/outlet data and current server rules. Partner `operationallyEligible` is only a synthetic projection, not a delivery eligibility decision.
4. **Partner eligibility:** under a consistent lock/revision strategy, verify ACTIVE account, current platform ELIGIBLE approval, AVAILABLE state, compatible service classification and permitted capability. Do not expose merchant/customer data as a consequence of eligibility.
5. **Duplicate assignment prevention:** enforce a database uniqueness/idempotency rule so one active request cannot have conflicting assignments; make retries deterministic and audit each real transition exactly once.
6. **Pickup authorization:** require a scoped assignment and authoritative restaurant READY/handoff evidence. Do not reinterpret KIOS `COLLECTED` as pickup proof or partner identity.
7. **Delivery completion:** introduce a separate validated delivery lifecycle and evidence trail; only an authorized assigned partner may complete its own assignment, with concurrency, replay, and audit protections.
8. **Cancellation handling:** specify who may cancel at each state, how restaurant and delivery records coordinate, and how retries/races preserve state and audit history.
9. **Failure recovery:** define outbox/retry/dead-letter/reconciliation rules, transaction boundaries, backup/restore and operator recovery. Do not silently lose accepted work or infer success after partial failure.
10. **Settlement responsibilities:** keep restaurant order totals, customer payment/refunds, partner compensation, and platform fees under explicitly assigned services and ledgers. M07E creates no charge, refund, invoice, settlement, or financial obligation.

Until those separately reviewed gates pass, do not convert intents to delivery, assign partners, expose an API, notify a partner, collect GPS, or mark a restaurant order complete on behalf of a delivery workflow. Public registration, customer ordering, real payment and physical printing stay disabled.
