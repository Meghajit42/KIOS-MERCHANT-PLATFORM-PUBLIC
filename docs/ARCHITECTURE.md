# Architecture overview

Application and domain services separate native restaurant operations from merchant/outlet identity and configuration. MerchantFoundation supplies tenant context, permissions and server-owned identity providers. MerchantOperations provides setup, inventory, seating, POS/kitchen coordination, analytics, billing drafts, subscriptions, usage and platform administration. Infrastructure repositories persist native orders and configuration. Public controllers enforce their existing feature/authentication/CSRF gates.

CustomerIdentity is an internal synthetic account foundation. CustomerOrders/OrderIntents records owned simulation drafts, price snapshots, validated transitions and immutable audit receipts in migration 037. It requires native tenant/outlet authority and trusted customer identity. No HTTP customer order endpoint or operational POS handoff exists. Customer/merchant identities are not interchangeable. API v1 read contracts are documented under M07A–M07D docs; do not infer customer login or write routes from internal service signatures.

The public catalogue adapter is synthetic and distinct from private hosted data. Source sharing does not authorize service activation or commercial release. Configuring tenants, migrations and deployments requires a separate controlled engineering workflow.
