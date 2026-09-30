# Phase 4 Plan — API Stabilization

**Goal (spec §13):** complete Storefront API v1; consistent errors, pagination and filters;
rate limits; OpenAPI finalized; TypeScript client generated in CI; contract tests; performance
review of hot endpoints. **Exit: API v1 contract frozen** — additive changes only afterwards.

## Milestones

| # | Milestone | Content |
|---|---|---|
| 4.1 | Conventions enforced by infrastructure, not by habit | `no-store` on every cart / checkout / account / order route by middleware; `ETag` + `304` on public catalog/content GETs; rate limits aligned with api-conventions §8 (cart 60/min, checkout 10/min, auth 5/min per IP **and** per account); `Retry-After` on every 429 |
| 4.2 | Customer account completion | `GET/PATCH /account/profile`, `PUT /account/password`, `POST /auth/forgot-password` + `/auth/reset-password` (hashed single-use tokens, emailed through the outbox, no account enumeration) |
| 4.3 | Checkout completion | `GET /checkout/{cart}/shipping-methods` for an address. `POST /checkout/payments` stays out of v1: it only exists for online gateways (owner decision, ADR-0015) and will be an additive endpoint |
| 4.4 | Content | `Content` module: pages made of allow-listed section types (JSON, validated per type), menus; `GET /pages/{slug}`, `GET /menus/{key}`; `GET /sitemap` + `GET /sitemap/{chunk}`; admin screens; default home page and menus seeded |
| 4.5 | Reviews and wishlist (flag-gated) | `reviews`: verified-purchase reviews, moderation in the admin, rating summary; `wishlist`: customer wishlist. Both `403 feature_disabled` when the flag is off |
| 4.6 | OpenAPI finalized | Money / Error / pagination components, bearer + header documentation, every endpoint's response described (no opaque `object`), committed snapshot `docs/api/openapi-v1.json` |
| 4.7 | Contract tests + drift guard | Every endpoint's real responses validated against the committed document (JSON Schema 2020-12); `api:contract` check fails CI when the code's document differs from the snapshot or when a change is not additive |
| 4.8 | TypeScript client | `platform-web/packages/api-client` generated from the snapshot with `openapi-typescript`; CI regenerates and fails on any hand edit |
| 4.9 | Performance review | Query budgets for hot endpoints enforced by tests (product list/detail, search, cart, quote, order); N+1s fixed |
| 4.10 | Freeze | api-conventions updated to the real surface; checkpoint report; v1 declared frozen |

## Out of scope (additive later)

`POST /checkout/payments` (online gateways), product Q&A, blog/articles, store locator,
multi-language content — none are required by the v1 surface the storefront needs.
