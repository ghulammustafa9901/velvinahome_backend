# Phase 2 Checkpoint — Catalog, Media, Inventory, Search

> **Historical record. Superseded in part by [plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md) and
> [ADR-0020](adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Date:** 2026-09-27 · **Status:** built, awaiting owner approval before Phase 3
Plan: [plans/phase-2.md](plans/phase-2.md)

## 1. Exit criteria (spec §13)

| Criterion | Result |
|---|---|
| Staff can build a full catalog with stock across locations | **Met.** Admin: products with option matrix and variants, categories (any depth), brands, collections (Action + API), locations; stock receive / transfer / count / adjust across locations. |
| Stock changes are fully traceable | **Met.** Every `on_hand` change is an append-only ledger movement written in the same transaction (DB-enforced: the app role cannot UPDATE/DELETE movements). Nightly reconciliation proves balance = ledger; daily valuation snapshots. |
| Search with typos, filters and facets works per tenant | **Met.** Typesense 30, one collection and one synonym set per brand; typo tolerance on words, exact SKUs; facets for brand, category, stock, price; Roman Urdu synonyms per brand. |
| Concurrency tests for stock pass | **Met.** 10 and 12 separate PHP processes race for 1 and 3 units: exactly 1 and 3 succeed. |

**Test suite:** 223 passing on real PostgreSQL 17, Redis and Typesense 30.2.

## 2. What was built

- **Seo:** redirects (chain-free, loop-free, hit counts), per-entity meta, `GET /redirects/resolve`.
- **Catalog:** brands, closure-table categories (move a subtree in two set-based statements),
  products with options and variants (unique option combinations and SKUs enforced in the
  database), collections, slug changes → 301, `products.manage` vs `products.publish`.
- **Inventory:** locations, ledger, weighted-average cost on receipt, transfers (both legs in
  one transaction), stock counts, reservations by atomic conditional update, ADR-0010 expiry
  rule (only cart reservations expire), low-stock events, reconciliation, snapshots,
  scheduled per tenant.
- **Search:** per-tenant collection created at provisioning, indexing queued after commit,
  `in_stock` follows the ledger, `search:reindex`, per-tenant synonyms (ADR-0014).
- **Media:** product images on the tenant's storage prefix; type by content, re-encoded to
  WebP (EXIF and payloads gone), random names, no SVG, 400/800/1600 WebP sizes.
- **Storefront API:** products (cursor pagination, allowlisted filters/sorts), product by id /
  by slug (301 on old slugs), categories tree and breadcrumbs, collections, search with facets,
  suggest; one error envelope everywhere (`error.code`, `details`, `request_id`).
- **Admin:** products, categories, brands, search synonyms, stock, ledger, locations.
- **Deploy behaviour:** `tenants:migrate` now also converges module defaults on existing
  brands without undoing their own role changes.

## 3. Found and fixed by the tests

1. SKU partial matching through token separators (ADR-0014).
2. Spatie-data treated an empty category list as "missing" in admin forms.
3. Synonym API change in Typesense 30 (per-collection synonyms removed).

## 4. Deferred / known gaps

- Purchase orders (only goods receipts), in-transit transfers, rule-based collections'
  evaluation, sale ranking (`sales_rank` is 0 until orders exist) — Phase 3/6.
- Rich-text descriptions with server-side sanitisation (plain text for now) — with Content.
- Product CSV import/export — Phase 6 (spec §11).
- Rate-limit headers and contract tests against the OpenAPI document — Phase 4.
- Local media is served by the API host (`/api/v1/media/...`) because there is no object
  storage on this machine; production uses R2 + CDN URLs.

## 5. Open questions (spec §16)

Unchanged from Phase 1: platform name/domain; VOG/SIX; topology; first payment gateway
(needed for Phase 3); transactional email provider (Phase 3).
