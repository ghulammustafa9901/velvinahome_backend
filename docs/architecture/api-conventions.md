# API Conventions

Spec §6.1. The **Storefront API** is the only public API in v1: catalog, search, content,
cart, checkout, customer account, plus reviews and wishlist where the feature flag is on.
Admin operations happen through Filament in-process, calling the same Actions — so an
admin/integration API can be added later without refactoring.

## 1. Versioning and hosts

- All routes live under `/api/v1`. **No breaking change is ever made inside a version**,
  because mobile apps cannot be force-updated. Additive changes only.
- Host strategy: browser traffic reaches the API through the Next.js BFF on the store's own
  domain; server-to-server and future mobile traffic use `api.<domain>`. See
  [ADR-0002](../adr/0002-api-host-strategy.md) and [installation.md](installation.md) §2.
- One store per installation (ADR-0020): there is no tenant parameter, header or host lookup.

## 2. Resource shape

Every successful response is an envelope with `data`, plus `meta` and `links` where relevant.

```jsonc
// GET /api/v1/products/01J8ZC0K9M2QF3T4W5X6Y7Z8AB
{
  "data": {
    "id": "01J8ZC0K9M2QF3T4W5X6Y7Z8AB",   // ULID, always. Never an integer id.
    "type": "product",
    "title": "Cotton Cushion Cover",
    "slug": "cotton-cushion-cover",
    "status": "active",
    "price": { "amount": 249900, "currency": "PKR", "formatted": "Rs 2,499" },
    "compare_at_price": { "amount": 299900, "currency": "PKR", "formatted": "Rs 2,999" },
    "in_stock": true,
    "variants": [ /* … */ ],
    "created_at": "2026-09-26T09:12:44Z"
  }
}
```

Rules:

- **`id` is always a ULID.** Internal auto-increment ids never appear in a URL, a payload or
  the OpenAPI schema (FINAL).
- **Money is always an object** — `amount` (integer minor units), `currency`, and a
  server-formatted `formatted` string. The client never does currency maths or formatting.
- Timestamps are ISO-8601 UTC with `Z`. The client renders in the store's timezone.
- Booleans are named as predicates (`in_stock`, `is_default`), never `flag`/`status` ints.
- Field names are `snake_case` in JSON; the generated TypeScript client exposes them as-is so
  the contract stays literal.
- Absent optional fields are `null`, never omitted, so generated types stay stable.

## 3. Errors

One shape for every failure, at every endpoint:

```jsonc
{
  "error": {
    "code": "validation_failed",      // stable, machine-readable, snake_case
    "message": "The given data was invalid.",
    "details": {                      // present only for validation_failed
      "email": ["The email field is required."],
      "items.0.quantity": ["The quantity must be at least 1."]
    },
    "request_id": "01J8ZC1RQ4V7..."   // matches the log/Sentry entry
  }
}
```

| HTTP | `code` examples | When |
|---|---|---|
| 400 | `bad_request` | malformed request |
| 401 | `unauthenticated` | missing/invalid customer token |
| 403 | `forbidden`, `feature_disabled` | policy denial, or a flag-gated feature switched off in the store settings |
| 404 | `not_found` | unknown resource |
| 409 | `conflict`, `stock_unavailable`, `price_changed`, `idempotency_key_reused` | state conflicts |
| 422 | `validation_failed` | field validation (`details` populated) |
| 429 | `rate_limited` | with `Retry-After` |
| 500 | `server_error` | never leaks a stack trace, path or SQL (spec §8) |

`409 stock_unavailable` and `409 price_changed` are first-class: the storefront revalidates
price and stock server-side at every checkout step, and these are the codes that drive the
"your cart changed" UI.

## 4. Pagination

Two modes, chosen per endpoint and never mixed:

- **Cursor pagination (default for large lists)** — product listings, search, orders:
  `?cursor=<opaque>&per_page=24`

  ```jsonc
  { "data": [ /* … */ ],
    "meta": { "per_page": 24, "has_more": true },
    "links": { "next": "/api/v1/products?cursor=eyJ…", "prev": null } }
  ```

- **Page pagination** — only for small, bounded lists where a page count is genuinely useful
  (e.g. a customer's addresses): `?page=2&per_page=20` with `meta.total`.

`per_page` is capped (default 24, max 100) server-side.

## 5. Filtering, sorting, sparse fieldsets, includes

Defined once here and applied identically everywhere.

| Concern | Convention | Example |
|---|---|---|
| Filter | `filter[<field>]`, comma-separated = OR | `?filter[brand]=velvina,prifit&filter[in_stock]=true` |
| Range | `filter[<field>][gte]` / `[lte]` | `?filter[price][gte]=100000` |
| Sort | `sort=<field>`, `-` prefix descends; allowlisted per endpoint | `?sort=-created_at` |
| Search | `q` | `?q=cushin cover` (typo tolerance is Typesense's job) |
| Includes | `include=`, allowlisted, depth-limited | `?include=variants,images` |
| Fields | `fields[<type>]=` | `?fields[product]=title,slug,price` |

Unknown or non-allowlisted filter/sort/include keys return `422 validation_failed` rather
than being silently ignored — silent ignoring hides client bugs.

## 6. Idempotency (FINAL — spec §6.1)

An `Idempotency-Key` header is **required** on:

- `POST /api/v1/checkout/orders` (order placement)
- `POST /api/v1/checkout/payments` (payment initiation) — **not in v1**: it exists only for
  online gateways, which the owner deferred (ADR-0015); it will be an additive endpoint
- refunds (admin path, same Action; the admin form carries one key per opened form)

Semantics (as built): the key is stored with the order (unique column) together with a
fingerprint of the request. A repeat with the same key and the same body returns the
**original response** with `200` and `Idempotent-Replayed: true` (the first answer is `201`,
`Idempotent-Replayed: false`). A repeat with the same key and a different body returns
`409 idempotency_key_reused`. Keys are kept with the order (not expired).

Inbound webhooks are deduplicated separately, by `(provider, provider_event_id)`.

## 7. Caching

- **Private by default, enforced by middleware** (`ApiCachePolicy`, outermost on every
  `/api/v1` route): a response is `Cache-Control: no-store, private` unless its controller
  explicitly marked it `public` — so carts, checkout, orders, account data and every error
  (including 401/429 produced by middleware) can never land in a shared cache.
- Public catalog and content endpoints are cache-friendly: `Cache-Control` with a short
  `s-maxage` plus `stale-while-revalidate`, and a weak `ETag`; a matching `If-None-Match`
  gets `304`.
- Server-side caching is Redis with **tag-based invalidation**, namespaced per installation by
  `REDIS_PREFIX`.
- Cart, checkout and account endpoints are `Cache-Control: no-store`, always.
- When catalog, category, page or settings data changes, the backend calls the storefront's
  revalidation endpoint so Next.js ISR tags are purged (spec §6.13).

## 8. Rate limits (spec §6.1, §8)

Defaults:

| Route group | Limit (as built) |
|---|---|
| Public catalog / content (GET) | 120 req/min per IP |
| Search | 60 req/min per IP |
| Cart, quote, shipping methods | 60 req/min per session (customer, else guest cart token, else IP) |
| Order placement, receipt upload, review submission | 10 req/min per session **and** 30 req/min per IP |
| Customer login / registration / password reset / password change | 5 req/min per IP **and** per account (email, else session), plus the account lockout |
| Account and other authenticated reads | 120 req/min per customer |
| Webhooks (inbound) | not limited by IP; protected by signature verification and dedup |

A flag-gated endpoint answers `403 feature_disabled` before authentication is checked.

`429` responses carry `Retry-After`. Cloudflare WAF sits in front of all of it.

## 9. Authentication (spec §6.2)

- Customers use **Sanctum** tokens. The storefront never holds one in browser JavaScript:
  login goes through a Next.js BFF route, and the token is kept in an `httpOnly`, `Secure`,
  `SameSite=Lax` cookie. See [ADR-0003](../adr/0003-bff-authentication-pattern.md).
- Guest checkout is supported; an account can be claimed after purchase.
- Internal Next.js → API calls carry a signed service header (HMAC over method, path, body
  hash and timestamp, short validity window) that is verified server-side. It authenticates
  the caller and grants nothing else.
- Staff never authenticate through this API. They use Filament session auth on the admin host,
  with mandatory 2FA.

## 10. OpenAPI and the generated client

- The OpenAPI document is **generated from code** (Scramble) and committed as
  **`docs/api/openapi-v1.json` — the v1 contract** ([ADR-0016](../adr/0016-api-contract-tooling-and-v1-freeze.md)).
- `php artisan api:contract --check` (CI) fails when the code's document differs from the
  committed one, names every change that would break a v1 client, and refuses lists with
  undescribed items. `--write` updates the snapshot after review.
- Shapes code inference cannot see are declared where the data is built: array-shape
  `@return` + `@scramble-return` on presenters, `Money` / `StringMap` / `JsonObject` schema
  components, the `Error` envelope for every 4xx, and the recursive `CategoryNode`.
- `packages/api-client` in `platform-web` is **generated from that document** (openapi-typescript)
  and never hand-written; its CI regenerates it and fails on any difference, and type-level
  tests check what the storefront relies on.
- **Contract tests** (`tests/Feature/Api/ContractTest.php`) validate real responses of every
  endpoint — success and error — against the committed document, **strictly**: an object may
  not carry fields the document does not declare.
- API v1 is **frozen at the end of Phase 4**; after that, additive changes only.

## 11. Endpoint surface of v1 (frozen)

The authoritative list is the contract, `docs/api/openapi-v1.json`. As frozen at the end of
Phase 4:

```text
GET    /api/v1/storefront/config            theme, tokens, feature flags, locale, currency
GET    /api/v1/products                     filter[category|brand|ids], sort, cursor paging
GET    /api/v1/products/{ulid}
GET    /api/v1/products/by-slug/{slug}      301/302 to the current slug after a change
GET    /api/v1/categories                   tree (CategoryNode)
GET    /api/v1/categories/{slug}            breadcrumbs, children
GET    /api/v1/collections/{slug}
GET    /api/v1/search                       Typesense; facets brand/category/in_stock/price
GET    /api/v1/search/suggest
GET    /api/v1/redirects/resolve?path=      old URL → new (WooCommerce migration)
GET    /api/v1/pages/{slug}                 page-builder sections; "home" is the home page
GET    /api/v1/menus/{key}
GET    /api/v1/sitemap | /sitemap/{chunk}   chunk list, then paths with lastmod
GET    /api/v1/payment-methods
POST   /api/v1/carts                        guest (returns token once) or customer
GET    /api/v1/carts/{ulid}
POST   /api/v1/carts/{ulid}/items
PATCH  /api/v1/carts/{ulid}/items/{item}
DELETE /api/v1/carts/{ulid}/items/{item}
POST   /api/v1/carts/{ulid}/coupon | DELETE /api/v1/carts/{ulid}/coupon
POST   /api/v1/carts/{ulid}/merge           on sign-in
GET    /api/v1/checkout/{cart}/shipping-methods?city=&province=&country_code=
POST   /api/v1/checkout/quote
POST   /api/v1/checkout/orders              Idempotency-Key required
GET    /api/v1/orders/{ulid}                X-Order-Token (guest) or owner
POST   /api/v1/orders/{ulid}/payment-proof  manual transfer receipt
POST   /api/v1/webhooks/{provider}          404 until a gateway adapter exists
POST   /api/v1/auth/register | login | logout | forgot-password | reset-password
GET    /api/v1/me                           kept for existing clients; = /account/profile
GET    /api/v1/account/profile | PATCH /api/v1/account/profile
PUT    /api/v1/account/password
GET    /api/v1/account/orders | /account/orders/{ulid}
GET|POST|PUT|DELETE /api/v1/account/addresses[/{ulid}]
GET|POST /api/v1/products/{ulid}/reviews    flag `reviews`
GET|POST|DELETE /api/v1/wishlist/items[/{ulid}]  flag `wishlist`
```

Deferred, additive when needed: `POST /checkout/payments` (online gateways).

Feature-flagged endpoints return `403 feature_disabled` when the store has the flag off —
they are never silently absent, so the client gets a diagnosable answer.
