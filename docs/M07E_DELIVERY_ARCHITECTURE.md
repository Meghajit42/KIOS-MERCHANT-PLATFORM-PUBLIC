# M07E Delivery Partner Foundation — Architecture

**Scope:** internal, synthetic delivery-partner identity foundation. This document describes the M07E Shift 01 implementation as it exists in the qualified candidate. It is not an operational delivery design or a claim that delivery is available.

## Identity domains

KIOS has distinct server-side identity domains for merchant staff, customers, and delivery partners. Partner accounts live in `delivery_partner_accounts`; they are not merchant staff, customer accounts, merchant memberships, or outlet memberships. A matching UUID or a session from another domain grants no partner authority. No partner-to-merchant/outlet membership is created by migration 038.

`PartnerIdentityProvider::currentPartnerId()` is a trusted future server authentication port. M07E supplies the interface but no credential store, login, session adapter, token issuer, public route, or partner-registration flow. Its implementation must derive identity from a verified server-side partner session, never request fields, unsigned headers, a merchant session, or a customer session.

Platform lifecycle operations use the existing `AuthenticatedUserProvider` and `MerchantProvisioner::administer` authorization/transaction path. M07E does not add a new platform-admin role or bypass existing RBAC.

## Account and operational projection

The persistent account has a stable generated UUID, normalized unique synthetic handle, constrained synthetic display label, account status, eligibility, availability, bounded synthetic coverage/capability JSON, monotonically increasing revision, and server UTC epoch timestamps. UUID, handle, and creation time are immutable; deactivation retains the identity and audit history.

Eligibility is an explicit platform decision for an ACTIVE partner. Availability is partner-controlled among `OFFLINE`, `AVAILABLE`, and `TEMPORARILY_UNAVAILABLE`. Coverage accepts at most eight unique `synthetic-area-*` values. Capabilities accept only `synthetic-foot` and `synthetic-cycle`, at most two unique entries. Changing eligibility or configuration forces OFFLINE. AVAILABLE requires ACTIVE, ELIGIBLE, and nonempty coverage and capability lists.

The returned `operationallyEligible` value is a synthetic readiness projection. It is not dispatch permission. The returned `dispatchAuthorized` value is always `false`; no assignment, pickup, route, completion, customer-location, or delivery-order state exists.

## Audit and concurrency

Successful create, lifecycle, eligibility, profile, configuration, and availability changes each append one `delivery_partner_events` row attributed either to the authorized native platform staff identity or to the same partner. The database enforces one event per partner revision and rejects audit update/delete. A failed audit insert rolls back the associated mutation.

Mutations require the caller's expected revision and lock the relevant row. Partner-owned calls check the trusted identity before the transaction, under the row lock, and before commit. Native platform changes use the existing membership-locking and READ COMMITTED transaction path. Stale, duplicate-state, or competing updates fail; the API has no idempotency-key receipt/replay facility.

## Database and migration

Migration `database/migrations/038_delivery_partner_identity.sql` adds exactly two tables: `delivery_partner_accounts` and `delivery_partner_events`. It references the existing `merchant_staff(user_id)` only for platform-event attribution and self-references partner actors for partner events. It creates no merchant/outlet membership, delivery request, assignment, dispatch, GPS, payment, or settlement tables. UUID columns use the existing ASCII binary convention. Times are server-generated UTC epoch seconds, not database-local timestamps.

The migration is included in the ordered migration runner as 038. Shift 01 qualification applied it to an isolated synthetic MariaDB database and verified replay as a no-op and populated backup/restore across the existing schema. Those test results do not authorize a hosted migration.

## Existing KIOS module boundaries

The foundation is adjacent to, not a replacement for:

- M07A's private API foundation and server authentication/authorization contracts.
- M07B's private restaurant discovery/catalogue contracts.
- M07C's separate customer identity domain.
- M07D's synthetic customer order intents and native restaurant order state ownership.
- Existing merchant/outlet, POS, preparation, KOT, inventory, billing, and platform-admin services.

M07E adds no public endpoint and no dispatch handoff. M07D `ACCEPTED` is still a synthetic intent state, not merchant acceptance of an authoritative restaurant order. Kitchen readiness remains owned by the existing preparation workflow. No existing POS, kitchen, KOT, payment, or customer identity service is changed by M07E's delivery service implementation.

## Disabled-by-default controls

`APP_ENV` must be `demo` and `KIOS_DELIVERY_IDENTITY_ENABLED=1` for the internal identity service to run. The service also fails closed unless each of the public-ordering, public-registration, payment, physical-printing, delivery-dispatch, delivery-registration, and delivery-GPS flags is explicitly `0`. The checked-in example keeps identity disabled and all public/operational delivery flags disabled. No control here enables public access or real delivery.

## Not implemented

There is no public/customer or partner authentication, registration, API route, dispatch/assignment worker, authoritative customer delivery order, merchant acceptance handoff, GPS/location processing, pickup/completion workflow, cancellation/recovery workflow, customer contact sharing, settlement, or commercial operation. Each requires a separately authorized design, implementation, security qualification, and release gate.
