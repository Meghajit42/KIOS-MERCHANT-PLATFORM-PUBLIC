# KIOS Merchant Platform — M07E public source export

Public repository: https://github.com/Meghajit42/KIOS-MERCHANT-PLATFORM-PUBLIC

KIOS is a development-only multi-merchant restaurant platform. This is a sanitized public source export with fresh history. The separately qualified private Hostinger demonstration is protected and remains unchanged. Owner UX acceptance is pending. This publication is not a commercial launch.

## Architecture and modules

PHP services enforce server-owned merchant/outlet identity, RBAC, optimistic revisions, idempotency and transactional audit records. MariaDB migrations 001–038 describe the database. Native operational services remain separate from synthetic customer-order intents and the internal delivery identity foundation.

- Merchant onboarding, outlet management, staff and centralized platform administration.
- Menu, serving prices, availability, kitchen configuration and outlet branding.
- Inventory/recipes; floors, tables and reservations.
- Internal staff POS, digital KOT, kitchen preparation and collection coordination.
- Operational analytics, nonfinancial billing drafts and synthetic usage metering.
- Subscription plans, entitlements, lifecycle and safe continuity controls.
- Visual customization drafts, publication history and design restoration.
- M07A private API foundation, M07B scoped discovery/catalogue, M07C internal customer identity, M07D owned synthetic order-intent lifecycle.

M07D is an internal PHP service, disabled by default. It has no customer HTTP endpoint, public customer authentication or session issuer. ACCEPTED means simulation only. It does not create POS orders, KOTs, inventory deductions, charges, usage billing, payments or delivery dispatches.

## Development and tests

See [developer setup](docs/DEVELOPER_SETUP.md), [architecture](docs/ARCHITECTURE.md), [export provenance](docs/EXPORT_PROVENANCE.md), [synthetic limitations](docs/SYNTHETIC_DATA_LIMITATIONS.md) and [third-party assets](docs/THIRD_PARTY_ASSETS.md).

Use PHP 8.3 with PDO MySQL/SQLite, GD, fileinfo, mbstring and Sodium; compatible MariaDB; Node.js; a trusted isolated browser. No executable dependency or installed package is bundled. Supply only locally generated credentials through the placeholder `.env.example`. Never connect tests to merchant databases or internet services. Follow AGENTS.md hardened isolation requirements.

The public fixture adapter independently generates 105 numbered items in 13 numbered categories. Prices use a documented synthetic formula; these are not restaurant menu records. A generic included SVG replaces dish photographs. Legacy fixture-dependent tests/data are intentionally omitted and listed in EXCLUSIONS.json. The full historical suite cannot be reproduced unmodified from this export.

Inside the approved hardened guest run `php tests/public-export.php` for catalogue, lookup, serving, pricing and image-adapter checks, and `php tests/tools/m07d-doc-contract.php .` for the internal documentation contract. PHP and JavaScript syntax checks are also required. The existing qualified full private candidate passed 236 PHP tests, 361 additional groups across 41 suites and 95-table recovery; those results do not qualify the altered public fixtures. Public-export test results are documented separately in docs/PUBLIC_EXPORT_VALIDATION.md.

## API documents

- [M07A architecture](docs/M07A_API_ARCHITECTURE.md) and [OpenAPI](docs/M07A_OPENAPI.json).
- [M07B developer integration](docs/M07B_DEVELOPER_INTEGRATION.md).
- [M07C customer identity](docs/M07C_CUSTOMER_IDENTITY_ARCHITECTURE.md) and [internal contract](docs/M07C_INTERNAL_IDENTITY_CONTRACT.md).
- [M07D architecture](docs/M07D_ORDER_LIFECYCLE_ARCHITECTURE.md), [state contract](docs/M07D_ORDER_STATE_CONTRACT.md), [internal contract](docs/M07D_INTERNAL_ORDER_CONTRACT.md), [restaurant boundary](docs/M07D_RESTAURANT_INTEGRATION_BOUNDARY.md) and [security/privacy](docs/M07D_SECURITY_AND_PRIVACY.md).

## Restrictions, licensing and collaboration

Public registration, customer authentication, ordering, real payments, commercial subscription charging, physical printing and delivery dispatch remain disabled. The hosted implementation is a private synthetic demonstration. No deployment workflow or GitHub Actions is enabled. Future work uses reviewed feature branches and pull requests in this new repository; the original repository is a preserved private historical archive. M07F is not authorized.

No open-source license has been granted and no LICENSE file is added. Public visibility alone does not grant a broad license to use, redistribute or sublicense the source. Review GitHub's platform terms and obtain owner permission for uses requiring additional rights. No rights are claimed over omitted shop media or third-party material.

## M07E internal delivery foundation

Stable synthetic partner UUIDs, platform lifecycle/eligibility controls, owned profile/configuration/availability, revision locks and immutable audit events are implemented in internal PHP services. Identity defaults off. No public partner/customer authentication, request persistence, dispatch, assignment, GPS, delivery payment or operational delivery is implemented.

See [delivery architecture](docs/M07E_DELIVERY_ARCHITECTURE.md), [state contract](docs/M07E_PARTNER_STATE_CONTRACT.md), [internal service contract](docs/M07E_INTERNAL_DELIVERY_CONTRACT.md), [dispatch boundary](docs/M07E_DISPATCH_INTEGRATION_BOUNDARY.md), and [security/privacy](docs/M07E_DELIVERY_SECURITY_PRIVACY.md). In the approved hardened guest run `php tests/tools/m07e-doc-contract.php .`. Migration 038 is additive; use a disposable database only. `tests/tools/m07e-partners.php` requires the private engineering runner and generated fixture inputs; its presence does not imply standalone public fixture qualification.
