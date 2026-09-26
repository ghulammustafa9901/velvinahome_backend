# API Conventions

Spec §6.1. The **Storefront API** is the only public API in v1: catalog, search, content,
cart, checkout, customer account, plus reviews and wishlist where the feature flag is on.
Admin operations happen through Filament in-process, calling the same Actions — so an
admin/integration API can be added later without refactoring.

## 1. Versioning and hosts

- All routes live under `/api/v1`. **No breaking change is ever made inside a version**,
  because mobile apps cannot be force-updated. Additive changes only.
- Host strategy: browser traffic reaches the API through the Next.js BFF on the brand's own
  domain; server-to-server and future mobile traffic use `api.<brand-domain>`. See
  [ADR-0002](../adr/0002-api-host-strategy.md).
- The tenant is always resolved from the request hostname, never from a parameter or header
  (spec §4.2).

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
- Timestamps are ISO-8601 UTC with `Z`. The client renders in the tenant timezone.
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
| 403 | `forbidden`, `feature_disabled` | policy denial, or a flag-gated feature off for this tenant |
| 404 | `not_found` | unknown resource **and** unknown tenant host |
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
- `POST /api/v1/checkout/payments` (payment initiation)
- refunds (admin path, same Action)

Semantics: the key is stored with the resulting resource (unique column on `orders` /
`payments`). A repeat with the same key and the same request body returns the **original
response**. A repeat with the same key and a different body returns
`409 idempotency_key_reused`. Keys are scoped per tenant and retained 24 hours minimum.

Inbound webhooks are deduplicated separately, by `(provider, provider_event_id)`.

## 7. Caching

- Public catalog and content endpoints are cache-friendly: `Cache-Control` with a short
  `s-maxage` plus `stale-while-revalidate`, and `ETag` support.
- Server-side caching is Redis with **tag-based invalidation**, tags scoped per tenant
  (`t:{tenant}:product:{ulid}`, `t:{tenant}:category:{ulid}`).
- Cart, checkout and account endpoints are `Cache-Control: no-store`, always.
- When catalog, category, page or settings data changes, the backend calls the storefront's
  revalidation endpoint so Next.js ISR tags are purged (spec §6.13).

## 8. Rate limits (spec §6.1, §8)

Every limiter key includes the tenant id. Defaults, tunable per tenant:

| Route group | Limit |
|---|---|
| Public catalog / content (GET) | 120 req/min per IP |
| Search | 60 req/min per IP |
| Cart mutations | 60 req/min per session |
| Checkout / payment initiation | 10 req/min per session |
| Customer login / registration / password reset | 5 req/min per IP **and** per account, with lockout |
| Webhooks (inbound) | not limited by IP; protected by signature verification and dedup |

`429` responses carry `Retry-After`. Cloudflare WAF sits in front of all of it.

## 9. Authentication (spec §6.2)

- Customers use **Sanctum** tokens. The storefront never holds one in browser JavaScript:
  login goes through a Next.js BFF route, and the token is kept in an `httpOnly`, `Secure`,
  `SameSite=Lax` cookie. See [ADR-0003](../adr/0003-bff-authentication-pattern.md).
- Guest checkout is supported; an account can be claimed after purchase.
- Internal Next.js → API calls carry a signed service header (HMAC over method, path, body
  hash and timestamp, short validity window) that is verified server-side. It authenticates
  the caller; it **never** selects the tenant.
- Staff and platform users never authenticate through this API. They use Filament session
  auth on their own domains, with mandatory 2FA.

## 10. OpenAPI and the generated client

- The OpenAPI document is **generated from code** (Scramble) and is the single source of truth
  for the contract.
- `packages/api-client` in `platform-web` is **generated from that document in CI and never
  hand-written**. A hand edit is a CI failure.
- **Contract tests** assert that every endpoint's real response validates against the
  published schema, so the document cannot drift from the code.
- API v1 is **frozen at the end of Phase 4**; after that, additive changes only.

## 11. Endpoint surface for v1 (planned)

```text
GET    /api/v1/storefront/config            theme, tokens, feature flags, nav, locale, currency
GET    /api/v1/products                     listing: filters, facets, sorting, cursor paging
GET    /api/v1/products/{ulid}
GET    /api/v1/products/by-slug/{slug}
GET    /api/v1/categories                   tree
GET    /api/v1/categories/{slug}
GET    /api/v1/collections/{slug}
GET    /api/v1/search                       Typesense-backed; facets + typo tolerance
GET    /api/v1/search/suggest               as-you-type prefix search
GET    /api/v1/pages/{slug}                 page-builder sections as JSON
GET    /api/v1/menus/{key}
GET    /api/v1/sitemap/{chunk}              chunked sitemap data
POST   /api/v1/carts                        create (guest or customer)
GET    /api/v1/carts/{ulid}
POST   /api/v1/carts/{ulid}/items
PATCH  /api/v1/carts/{ulid}/items/{ulid}
DELETE /api/v1/carts/{ulid}/items/{ulid}
POST   /api/v1/carts/{ulid}/coupon
POST   /api/v1/carts/{ulid}/merge           on login
GET    /api/v1/checkout/{cartUlid}/shipping-methods
POST   /api/v1/checkout/quote               recompute totals server-side
POST   /api/v1/checkout/orders              Idempotency-Key required
POST   /api/v1/checkout/payments            Idempotency-Key required
POST   /api/v1/webhooks/{provider}          signature-verified, stored raw, queued
POST   /api/v1/auth/login | register | logout | forgot-password | reset-password
GET    /api/v1/account/profile | orders | orders/{ulid} | addresses
GET    /api/v1/products/{ulid}/reviews      flag-gated
POST   /api/v1/wishlist/items               flag-gated
```

Feature-flagged endpoints return `403 feature_disabled` when the tenant has the flag off —
they are never silently absent, so the client gets a diagnosable answer.
