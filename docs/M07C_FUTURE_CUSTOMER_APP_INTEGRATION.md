# M07C Future Customer Application Integration Preparation

**Current status:** Preparation guidance only. No customer-facing authentication, registration, session, contact-verification, recovery, or identity API is implemented or enabled. The internal M07C service must remain inaccessible from public routes until a separately authorized and qualified integration exists.

## Existing internal boundary

CustomerAccounts provides internal synthetic provisioning, state transitions, own-profile access, synthetic display-name updates, and explicit versioned consent. CustomerIdentityProvider is a trusted server-side port whose currentCustomerId() supplies an identity already established by trusted application code. It is not a credential verifier and must never be implemented by copying a customer ID from a request, unsigned header, query, merchant cookie, or caller-controlled token.

Merchant accounts, staff accounts, outlet memberships, permissions and sessions remain separate. A merchant or staff session must not be accepted as a customer identity. A customer identity must not grant merchant, staff, delivery-partner, platform-administrator, or billing authority. Delivery identities and credentials require a separate domain.

## Gates before customer-facing integration

A future proposal must define and qualify each of the following before enabling any public customer access:

1. **Authentication design:** approved authenticators, enrollment and credential lifecycle; rate limits; secure password hashing if passwords are selected; MFA or step-up policy where warranted; enumeration-resistant errors; abuse controls; and session revocation on account state changes.
2. **Verified contact:** explicit supported contact channels, proof issuance and delivery, expiry and attempt limits, atomic single use, change-of-contact re-verification, and no bypass for unverified accounts. Current contact_state is only UNVERIFIED and there is no contact field or delivery service.
3. **Customer sessions:** server-owned session creation after successful authentication; secure cookie and CSRF policy for browsers; expiry, rotation, logout, revocation, device policy, and auditable identity provenance. No customer session issuer exists.
4. **Account recovery:** separately reviewed proof of control, high-entropy one-time recovery challenges stored only as hashes, bounded lifetime and attempts, atomic consumption, generic responses, session revocation, immutable audit events, and no implicit reactivation of DEACTIVATED accounts. Recovery is design-only today.
5. **API authentication:** versioned customer routes must authenticate a server-established customer principal, apply authorization on every request, enforce rate and abuse controls, use CSRF protection for cookie-authenticated mutations, validate content types and schemas, and return privacy-safe errors. Do not expose CustomerAccounts directly as an HTTP controller.
6. **Data privacy:** define purpose limitation, notice and consent semantics, access/export/correction/deletion procedures, retention and legal-hold policy, logging minimization, breach response and applicable legal review. Existing consent storage is append-only but is not by itself legal compliance.
7. **Object authorization:** any future order, restaurant, delivery, or support lookup must prove explicit customer ownership and independently scope merchant, outlet, order, and delivery records. Shared customer identity must not enable cross-merchant data access.
8. **Operational safety:** keep registration and customer endpoints off by default; use synthetic identities and disposable databases; qualify tenant boundaries, concurrency, recovery, backup/restore, browser flows and denied paths in the hardened environment before any limited exposure.

## Future adapter shape

When authorized, a concrete CustomerIdentityProvider should be constructed only after the trusted authentication middleware has validated a revocable customer session. Its currentCustomerId() must derive from that verified server-owned principal and return null when no valid customer principal exists. Session expiry, revocation, account status and customer UUID should be checked at the trust boundary; services must continue performing their own account ownership/state checks. Merchant authentication adapters must remain distinct types.

A future HTTP layer should translate authenticated requests into typed service calls; it should not accept a customer UUID as proof of authority. For example, after a future trusted session middleware has established a customer principal, the adapter may call the own-profile operation for the session-bound UUID. This is a design example only: there is no route or middleware today.

## Consent and identity lifecycle

The existing internal service records explicit terms, privacy, or marketing grants/withdrawals with a symbolic policy version and account version. A future customer interface must show the relevant notice and obtain a deliberate choice; it must not treat account creation, login, or silence as consent. The current service returns only the minimized account projection, not consent history, and offers no customer consent-history query. Any future presentation or consent retrieval contract needs separate design and authorization.

PENDING, ACTIVE, SUSPENDED, and DEACTIVATED are internal account states. Public authentication policy must separately define which states can authenticate and how suspension, deactivation, contact verification, and session revocation interact. Do not assume ACTIVE alone is sufficient for customer login.

## Explicitly out of scope today

No public registration or login, password/credential store, OTP/email/SMS transport, customer session/token issuance, recovery, customer-facing API, customer-order history, delivery identity, marketplace browsing, public ordering, payments, or dispatch is present or authorized by Shift 02.
