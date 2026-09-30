# Phase 4 Checkpoint â€” API Stabilization

**Date:** 2026-09-27 Â· **Status:** built; **Storefront API v1 is frozen** (additive changes only)
Plan: [plans/phase-4.md](plans/phase-4.md) Â· Decisions: [ADR-0016](adr/0016-api-contract-tooling-and-v1-freeze.md)
Contract: [`docs/api/openapi-v1.json`](api/openapi-v1.json) Â· Conventions: [architecture/api-conventions.md](architecture/api-conventions.md)

## 1. Exit criterion

| Criterion | Result |
|---|---|
| **API v1 contract frozen** | **Met.** The contract is committed; `api:contract --check` in CI fails on any drift and names non-additive changes; contract tests validate every endpoint's real responses (success and error) strictly against it; the TypeScript client is generated from it and type-checked. |

**Test suite:** 296 passing (1 skipped), up from 269 — including contract tests for every endpoint group, conventions, account, content, reviews/wishlist and query budgets. Larastan level 8 and Pint clean; `api:contract --check` passes.

## 2. Milestones

| # | Milestone | Result |
|---|---|---|
| 4.1 | Conventions by infrastructure | `ApiCachePolicy` makes every API response `no-store, private` unless the controller opts into `public` (errors, 401s and 429s included); public reads get weak ETags and `304`. Rate limits match the conventions table, per tenant, per session and per IP/account; `Retry-After` on every 429. Flag-gated endpoints answer `403 feature_disabled` before auth. |
| 4.2 | Customer account | Profile read/update, password change (other sessions revoked), forgot/reset password (hashed single-use tokens, 60 min, no enumeration, link only to the brand's own storefront domain from landlord records, sign-outs everywhere on reset). |
| 4.3 | Checkout | `GET /checkout/{cart}/shipping-methods` for a destination. `POST /checkout/payments` deliberately not in v1 (online gateways deferred â€” additive later). |
| 4.4 | Content | New `Content` module: page-builder pages from seven allow-listed section types, validated per field (plain text, site-relative or https links only, unknown fields refused); menus; default home page and menus seeded per brand; admin page builder; sitemap data (`/sitemap`, `/sitemap/{chunk}`) assembled from Catalog and Content sources. |
| 4.5 | Reviews and wishlist | New `Reviews` module: verified-purchase reviews (delivered orders only), moderation, rating summary; wishlist in Customers, with `filter[ids]` on the product listing for live product cards. Both flag-gated. |
| 4.6 | OpenAPI finalized | 49 operations on 42 paths (the development-only local media route is excluded); no opaque or untyped schemas; shared `Money`, `Error`, `CategoryNode`; bearer security; secret/idempotency headers; error statuses per route. |
| 4.7 | Contract tests + drift guard | `ContractTest` covers every endpoint group, strict mode; `api:contract --check` in CI. |
| 4.8 | TypeScript client | `platform-web/packages/api-client`: generated `schema.ts`, `createStorefrontClient()`, `Money`/`ApiError` types, type-level contract tests; platform-web CI verifies regeneration and typechecks (storefront-only jobs wait for `apps/storefront`). |
| 4.9 | Performance review | Query-count tests: product listing and cart/quote costs do not grow with items (no N+1); fixed budgets per hot endpoint. |

## 3. Found and fixed along the way

1. **Rate-limited requests answered 500** instead of 429: the error renderer passed integer
   header values to Symfony. Found by the new conventions test; fixed and covered.
2. **`auth` middleware ran before the cache policy and feature flags** (Laravel's middleware
   priority), so 401s escaped the no-store rule and gated routes answered 401 instead of 403.
   Fixed through the middleware priority list.
3. Scramble could not see through `array_map`, loops, `(object)` casts or private response
   wrappers; the contract showed `string` or `{}` in places. Fixed at the source (shapes,
   value objects, direct responses) and guarded in `api:contract`.
4. The contract normaliser turned empty JSON objects into arrays (`{}` â†’ `[]`); canonical JSON
   is now built from objects.

## 4. Deferred / known gaps

- `POST /checkout/payments` and gateway webhooks processing â€” with the first online gateway.
- Product Q&A, blog/articles, multi-language content â€” not in v1.
- `Cache-Control` values are per controller; tag-based server-side response caching and
  storefront revalidation webhooks arrive with the storefront (Phase 5).
- `platform-web` keeps a copy of the contract (`pnpm sync:api-contract`); cross-repository CI
  artifact exchange can replace the copy when the repositories get a shared CI.

## 5. Open questions (spec Â§16)

Transactional email provider (needed before launch; mail already flows through Laravel's
mailer and the outbox). First online payment gateway (deferred by the owner).
