# M07B private discovery and catalogue integration

## Status and access

This is a private demonstration API for authorized KIOS developers. It is not approved for public customer or delivery applications. There is no customer login, bearer-token issuer, public CORS grant, ordering endpoint, payment endpoint or delivery dispatch endpoint.

The existing private-site gate and a normal KIOS staff session are both required. Basic gate authentication alone does not grant merchant access. Use an approved browser session signed in through the existing merchant portal, then make same-origin requests with the browser's session credentials. Never place credentials, cookies or tokens in URLs, source code or mobile applications. A future public client needs a separately authorized identity and consent design.

The caller must be a current member of the requested merchant and outlet, have native `ReadOrders` authority, and retain menu read entitlement. The `merchant` and `outlet` query values select a scope; they do not grant it. Foreign merchant/outlet requests are denied. Discovery returns at most the selected authorized outlet. It does not enumerate a marketplace.

## Routes

All routes support GET and HEAD. HEAD returns the corresponding status without a body. Other methods receive 405. Requests use the existing `/api/v1/` envelope and errors.

```http
GET /api/v1/restaurants?merchant=MERCHANT_UUID&outlet=OUTLET_UUID
GET /api/v1/customer-catalogue?merchant=MERCHANT_UUID&outlet=OUTLET_UUID
```

Both routes require server-side `KIOS_PRIVATE_DISCOVERY_ENABLED=1`; this flag defaults to `0`. Existing private API, lifecycle, public-ordering, registration, payment and printing restrictions remain in force. `KIOS_PRIVATE_DISCOVERY_SCOPES` is an operator-owned JSON list, empty by default. It can explicitly enable a synthetic merchant/outlet and list its categories and cuisines. Request parameters cannot change visibility or classifications. Missing or false visibility returns an empty data array; malformed server settings fail closed.

Example placeholder configuration:

```json
[{"merchant":"MERCHANT_UUID","outlet":"OUTLET_UUID","visible":true,"categories":["restaurant"],"cuisines":["synthetic-cuisine"]}]
```

## Parameters

Both routes accept required UUID `merchant` and `outlet`, plus shared `page` (1–10000, default 1), `per_page` (1–100, default 20), and `q` (up to 80 UTF-8 characters). Search is case-insensitive name search; restaurant discovery matches both outlet and merchant names. `category` is an exact slug: for discovery it filters the configured restaurant category; for the catalogue it filters the native product category. `cuisine` is an exact configured synthetic cuisine slug. `sort` accepts `name` or `name-desc`, with product ID as a stable tie-breaker. Unknown parameters, arrays, malformed slugs, invalid search text and out-of-range pages return `INVALID_REQUEST` (400). Filtering and sorting happen before pagination. Separate page requests are not a shared snapshot.

## Responses

Success uses `api_version`, `request_id`, `data`, `error` and `pagination`. Example discovery response:

```json
{"api_version":"v1","request_id":"0123456789abcdef0123456789abcdef","data":[{"merchantId":"MERCHANT_UUID","outletId":"OUTLET_UUID","name":"Synthetic Outlet","merchantName":"Synthetic Restaurant","categories":["restaurant"],"cuisines":["synthetic-cuisine"],"acceptingOrders":false,"orderingStatus":"CLOSED"}],"error":null,"pagination":{"page":1,"per_page":20,"total":1}}
```

Catalogue entries include `productId`, `merchantId`, `outletId`, `name`, `category`, `categoryName`, nullable `description`, `servingOptions`, `displayPricePaise`, `imageReference`, nullable `vegetarian`, `available` and `acceptingOrders:false`. Serving options and prices come from the existing native catalogue. Display price is the lowest listed serving price, expressed in integer paise. It is informational and does not authorize checkout.

Only products confirmed active and available by the native catalogue and product record are returned. Unavailable products are omitted. Existing image references are passed through; resolve them using the authorized outlet's existing asset conventions. This API does not upload images or grant access to another outlet's assets. Legacy vegetarian `false`/default is represented as null because the stored boolean does not distinguish confirmed nonvegetarian from unclassified. The API does not infer cuisine or food classification.

Even when a restaurant is visible, discovery explicitly returns `acceptingOrders:false` and `orderingStatus:CLOSED`. No value in these read responses overrides shop lifecycle or checkout validation.

## Errors and safe client behavior

- `400 INVALID_REQUEST`: correct parameters and retry with a new request.
- `401 AUTHENTICATION_REQUIRED`: complete normal private gate and staff sign-in.
- `403 ACCESS_DENIED`: do not retry with another merchant/outlet identifier; obtain legitimate membership and permission.
- `404 NOT_FOUND`: route or private discovery feature is disabled/unknown.
- `405 METHOD_NOT_ALLOWED`: use GET or HEAD.
- `413 REQUEST_TOO_LARGE`: send no request body; API reads are bodyless.
- `429 RATE_LIMITED`: honor `Retry-After`; limit is 60 authenticated requests per minute (anonymous requests are denied and separately bounded).
- `503 UNAVAILABLE`: retry only with backoff; do not expose internal details.

All responses are private/no-store and carry a request ID. Do not log cookies or authorization headers. A repeated read does not create an order or payment, but generates a new access audit event and request ID.

## Development limits

Use synthetic fixtures and the established isolated test workflow. Do not connect to production data. Public registration, customer ordering, payments, delivery dispatch and physical printing remain disabled. This guide describes backend behavior only; it does not authorize hosted deployment, public client access or a later M07B shift.
