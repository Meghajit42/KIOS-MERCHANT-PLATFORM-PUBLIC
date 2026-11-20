# M07C Customer Identity Architecture

**Status:** Internal synthetic identity foundation only. This document describes the implementation in app/CustomerIdentity/CustomerAccounts.php, CustomerIdentityProvider.php, and migration 036_customer_identity.sql. Future-facing items are marked explicitly.

## Identity model

A customer account is separate from merchant organizations, outlets, staff identities, merchant memberships, and delivery-partner identities. Migration 036 introduces three customer-domain tables: customer_accounts, customer_consents, and customer_security_events. These records have no merchant or outlet membership relation. Customer ownership must therefore never be inferred from a merchant session or role.

CustomerAccounts::create() creates an account identifier through the existing Kios\MerchantFoundation\Identity::generate() UUID-v4 utility. The resulting identifier is stable across account state changes. The service does not accept a client-supplied customer identifier when creating an account.

## Provisioning and lifecycle

Provisioning is an internal service operation, guarded by the existing native MerchantProvisioner::administer() authorization transaction. Only an authenticated, currently authorized platform administrator can provision an account or change its lifecycle state. Merchant owners and staff are not sufficient.

New accounts start as PENDING, UNVERIFIED, version 1. Provisioning accepts a normalized synthetic handle and synthetic display label only. Handles are trimmed, lowercased, validated against the synthetic-... pattern, and protected by a database unique key. Display labels must start with Synthetic . Contact state has only the UNVERIFIED value; no email or telephone contact fields or verification operation exist.

Implemented transitions are:

| Current state | Allowed next states |
| --- | --- |
| PENDING | ACTIVE, DEACTIVATED |
| ACTIVE | SUSPENDED, DEACTIVATED |
| SUSPENDED | ACTIVE, DEACTIVATED |
| DEACTIVATED | none |

The service requires the expected account version and locks the account row before a transition. Stale versions and disallowed transitions are rejected. DEACTIVATED is terminal. This is an internal lifecycle state, not a grant of public access.

## Customer ownership and profile operations

Customer-owned methods require the distinct CustomerIdentityProvider interface and the requested customer UUID must equal currentCustomerId(). The service checks identity again inside its owned transaction. A merchant AuthenticatedUserProvider is not accepted by these methods. The interface is only a trusted server-side port: no concrete customer authentication provider is wired into an HTTP route or application bootstrap.

An active account may update only its synthetic display name and append an explicit consent decision. The service does not expose edits to UUID, handle, contact state, status, role, or ownership. A suspended customer can read their own minimized profile/status but cannot update the profile or consent. A deactivated account cannot use retained customer identity for profile access.

Successful account operations return a minimized projection containing only customer_id, account_handle, display_name, status, contact_state, and version. No customer lookup/list/export operation is implemented.

## Consent

CustomerAccounts::consent() records an explicit decision for one of terms, privacy, or marketing, with a caller-supplied symbolic policy version matching the service's restricted version pattern. Grant and withdrawal are append-only records; each operation increments the account version. Consent is not inferred or automatically granted. The consent row is not returned by the service; the operation returns the minimized account projection.

Migration 036 links consent to the customer account with a restrictive foreign key, enforces one consent row per account version, constrains granted to 0/1, and prevents update or deletion through immutable-history triggers.

## Security audit and transaction behavior

Account creation and platform lifecycle transitions run through the existing platform-administration transaction. Customer-owned reads and writes run in a service-owned READ COMMITTED transaction with a row lock and repeated identity/status checks. Calls are rejected if a transaction is already active because the service owns its transaction boundary.

Each successful mutation writes a customer_security_events row with the customer ID, actor domain (PLATFORM or CUSTOMER), actor ID, action code, resulting account version, and Unix epoch timestamp from server time(). No profile, contact, password, token, or consent text is placed in the security-event record. Both consent and security-event tables reject UPDATE and DELETE. Account, consent, and audit mutation failures roll back atomically.

The schema uses UUID and opaque event identifiers, foreign keys with ON DELETE RESTRICT, explicit version columns, and epoch-second timestamps. The service supplies timestamps; it does not depend on database-local timezone defaults for these events.

## Privacy and data-handling boundaries

Implemented controls include account-scoped reads, typed separation from merchant identity, a minimized field projection, synthetic-only account input validation, append-only versioned consent, immutable security history, restrictive foreign keys, and transactional rollback when audit insertion fails. No real contact details or credentials are stored by this identity foundation.

This is not a complete privacy program or legal-compliance certification. The implementation has no customer export, self-service erasure/deactivation, retention schedule, legal-hold workflow, or public privacy-request interface. Deactivation preserves account, consent, and audit history; it does not erase records. Retention and deletion policy require a separately approved product and legal decision.

## Not implemented

The following must not be represented as existing functionality:

- Public customer registration, login, authentication, or customer-facing sessions.
- Password or credential storage for customers.
- Email/SMS collection, delivery, or contact verification.
- Customer password reset or account recovery.
- Customer-facing API routes, access tokens, or order-history APIs.
- Customer-order or delivery-partner identity linkage.
- Public ordering, payments, or delivery dispatch.

CustomerIdentityProvider is an integration seam, not an authenticator. A future implementation must establish verified session provenance, expiry and revocation, contact verification, recovery, privacy boundaries, and separate authorization before any customer-facing use is enabled.
