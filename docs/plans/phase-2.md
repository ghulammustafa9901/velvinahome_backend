# Phase 2 Plan — Catalog, Media, Inventory, Search

**Exit criteria (spec §13):** staff can build a full catalog with stock across locations;
stock changes are fully traceable; search with typos, filters and facets works per tenant;
concurrency tests for stock pass.

Every table below is a **tenant** table. Money is `BIGINT` minor units + currency
(ADR-0008). Public ids are ULIDs.

## Modules introduced

| Module | Owns | Depends on (contracts only) |
|---|---|---|
| `Seo` | `seo_meta`, `redirects`; slug history → automatic 301s | — |
| `Catalog` | brands, categories (+ closure table, ADR-0006), products, options, variants, collections, search documents | `Seo`, `Inventory` (stock reader), `Settings` |
| `Inventory` | locations, stock levels, movements ledger, reservations, receipts, transfers, counts, snapshots, reconciliation | `Catalog` (variant reference) |

## Milestones

### 2.1 Seo + Catalog core
Tables: `redirects`, `seo_meta`, `brands`, `categories`, `category_closure`,
`products` (partial unique slug), `product_options`, `product_option_values`,
`product_variants` (partial unique SKU, price/compare-at/cost), `variant_option_values`,
`product_category`, `collections`, `collection_product`.
Actions: `CreateBrand`, `CreateCategory`, `MoveCategory`, `CreateProduct` (always ≥1 variant),
`UpdateProduct` (slug change writes a 301), `PublishProduct`, `ArchiveProduct`,
`AddVariant`, `UpdateVariantPrice`, `CreateCollection`, `SetCollectionProducts`.
Tests: every product has a default variant; slug change → redirect; closure-table
integrity on move; SKU uniqueness; permissions enforced in Actions; money never float.

### 2.2 Inventory ledger
Tables: `locations`, `stock_levels`, `inventory_movements` (append-only), `stock_reservations`,
`suppliers`, `goods_receipts` (+ lines), `stock_transfers` (+ lines), `stock_counts` (+ lines),
`stock_snapshots_daily` (append-only), `inventory_reconciliations`.
Actions: `AdjustStock` (mandatory reason), `ReceiveStock` (weighted-average cost),
`TransferStock`, `ApplyStockCount`, `ReserveStock`, `ReleaseReservation`,
`CommitReservation` (ADR-0010), `ExpireReservations`, `ReconcileInventory`,
`SnapshotInventory`. Every `on_hand` change writes a movement in the same transaction; rows
locked in ascending `(variant_id, location_id)` order.
Tests: **concurrency — N parallel reservations for the last unit sell exactly one**
(separate PHP processes, real PostgreSQL); ledger sum equals on_hand; reconciliation detects
drift; append-only enforced; backorder variants may go negative, others never.

### 2.3 Search
Scout + Typesense, per-tenant collection `{tenant_id}_products`, created by a new
provisioning step and by `search:reindex`. Document shape per ADR-0005; typo tolerance off
for SKUs; per-tenant synonyms (`search_synonyms`). Indexing queued after commit.
Tests: isolation channel 5 (a document in B is never returned for C), typo query, facet
counts, draft products not indexed.

### 2.4 Storefront API (catalog)
`GET /products` (cursor pagination, filters, sort allowlist), `GET /products/{ulid}`,
`GET /products/by-slug/{slug}` (follows redirects with 301 info), `GET /categories`,
`GET /categories/{slug}`, `GET /collections/{slug}`, `GET /search`, `GET /search/suggest`.
Error envelope per api-conventions §3.

### 2.5 Admin
Filament resources: brands, categories, products (with variants), collections, locations,
suppliers; inventory workflow pages: adjust, receive, transfer, count; stock ledger view;
search synonyms.

### 2.6 Media
spatie/laravel-medialibrary on the tenant disks, queued WebP conversions, upload validation
(MIME by content, size, re-encode, no SVG). Local disk until MinIO/R2 is available.

## Out of scope

Pricing pipeline, carts, orders (Phase 3). Purchase orders beyond goods receipts. Lots,
serials, bundles (design allows them later).
