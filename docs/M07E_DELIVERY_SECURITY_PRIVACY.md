# M07E Delivery Foundation — Security and Privacy

This is an engineering description of the implemented synthetic foundation, not a legal or regulatory compliance assessment.

## Identity and authorization separation

Merchant staff, customers, and delivery partners use distinct identity interfaces and storage domains. The partner table has no merchant/outlet membership and no customer relation. UUID equality is not authorization. Platform account lifecycle/eligibility operations reuse the existing platform administrator authorization service. Partner self-service methods require a trusted server-side `PartnerIdentityProvider` resolving to the same account before access, under a database row lock, and before commit. That interface is a boundary contract, not an implemented authentication mechanism.

There are no public endpoints, partner credentials, customer credentials, login sessions, registration, or token mechanisms in M07E. A future adapter must be separately designed, authenticate the person, enforce revocation, bind the principal to the correct domain and tenant, and be covered by transport/session/CSRF/rate-limit security tests. A caller-supplied partner UUID must never construct trusted identity.

## Minimized synthetic fields

The current partner record stores only a generated UUID, synthetic normalized handle, synthetic display label, account/eligibility/availability states, bounded synthetic service-area and capability labels, revision, and server UTC epoch timestamps. It has no real names, phone or email contacts, street addresses, identity documents, passwords, tokens, payment fields, or coordinates/location history. The syntax-only future order reference is not persisted and contains identifiers only; it is not evidence of identity or ownership.

This field minimization does not constitute a complete privacy program; it is not a complete privacy program or legal compliance assessment. Consent, purpose limitation, retention/deletion rules for future personal data, access logging policy, data-subject workflows, breach response, jurisdiction-specific legal review, and operational monitoring remain unimplemented.

## Database and mutation protections

- SQL uses prepared statements with native prepares and requires the MariaDB/PDO MySQL driver.
- Platform operations run through existing authorization, membership-lock and transaction controls. Partner-owned mutations use READ COMMITTED transactions, row locks, and an expected revision.
- Account, eligibility and capability invariants are checked in service logic and by migration 038 constraints.
- All allowed lifecycle transitions reset eligibility and availability. Configuration/eligibility changes reset availability. AVAILABLE is constrained to ACTIVE and ELIGIBLE with configured classifications.
- Each successful revision is paired with one actor-attributed audit event in the same transaction. The database rejects audit update/delete and identity delete/rekey. Audit insertion failure rolls back the mutation.
- Stale revisions and invalid/duplicate-state transitions fail rather than silently overwriting concurrent changes. There is no request-id receipt facility, so this is optimistic concurrency, not general retry idempotency.

## Isolation and disclosure limits

Owned reads are minimized projections. Cross-partner lookup/update is rejected by the service authorization boundary and tests; merchant/customer provider types are not accepted by partner-owned methods. The `operationallyEligible` flag is simulation-only; `dispatchAuthorized` is hard-coded false. M07E does not select or disclose a customer record, location, address, order detail, phone number, or delivery instructions.

The trusted provider interface assumes a server-controlled implementation. It cannot protect against a future compromised or malicious trusted adapter, compromised platform credentials, database administrators, or infrastructure operators. There is no transport/API layer to assess for public exposure, and no public delivery functionality has passed qualification.

## Operational defaults

The feature defaults off in `.env.example`. The service requires demo mode and explicit enablement while public ordering, public registration, payments, physical printing, delivery dispatch, delivery registration, and delivery GPS remain explicitly disabled. These flags are guard conditions, not a substitute for deployment controls. Production/staging exposure, real partner onboarding, GPS, dispatch, payments, and commercial operations are not authorized by this foundation.
