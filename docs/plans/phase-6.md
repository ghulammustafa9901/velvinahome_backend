# Phase 6 Plan — Operations & Growth Modules + Migration Tools

**Goal (spec §13):** reporting (aggregations + dashboards); reviews and wishlist; blog and custom
content types; additional promotions; abandoned-cart notifications; first courier adapter;
additional payment adapters; WooCommerce and CSV importers; tenant onboarding polish.
**Exit:** a real tenant's WooCommerce data can be imported with redirects; reports match raw data.

## Owner decisions (2026-09-30)

- **Payment adapters (JazzCash/Easypaisa): deferred.** Launch stays COD + manual transfer
  (ADR-0015); the adapter contract and webhook intake already exist.
- **Courier: no account yet.** Build the courier adapter contract, a manual courier (tracking
  number + tracking-link template) and the booking/tracking plumbing; the first live adapter
  follows when a courier and its sandbox credentials are chosen.
- Reviews and wishlist were delivered in Phase 4.

## Milestones

| # | Milestone | Content |
|---|---|---|
| 6.1 | Reporting | `Reports` module: daily sales and product aggregates per brand (store time zone), rebuilt per affected day from raw orders (idempotent), triggered through the outbox and a nightly sweep; `reports:verify` compares aggregates with raw data; admin dashboard (sales, AOV, COD confirmation / RTO rates, top products, low stock) and a sales report page with date range and CSV export |
| 6.2 | WooCommerce importer | `Imports` module: import runs (queued, progress, dry run, validation report, resumable) over the WooCommerce REST API v3 (credentials encrypted): categories, products with variations and images, customers, historical orders (read-only), and **old URL → new URL 301 redirects**; external-id mapping makes re-runs update instead of duplicate |
| 6.3 | CSV importers | Products (with variants) and stock levels from CSV: dry run with per-row errors, then apply through the same Actions the admin uses |
| 6.4 | Blog and custom content types | Articles (SEO, cover image, tags, publish date) and staff-defined content types with allow-listed field kinds; admin screens; additive API (`/articles`, `/content/{type}`); sitemap source; storefront `/blog` pages with `Article` JSON-LD |
| 6.5 | Additional promotions | Automatic promotions without a code: spend tiers, buy X get Y, and scheduled percentage sales on products/categories; priced in the pipeline before coupons, one automatic promotion per line, visible in quotes and orders |
| 6.6 | Abandoned-cart notifications | Flag-gated; a scheduled job finds idle carts with a known email, sends one reminder through the outbox with a signed recovery link, honours opt-out; storefront recovery route restores the cart |
| 6.7 | Courier framework | `CourierAdapter` contract (book, track, cancel, label), registry, manual courier with tracking-link templates, shipment booking/tracking fields, status sync job for adapters that support it |
| 6.8 | Tenant onboarding checklist | Platform panel: per-brand checklist computed from the brand's real state (domains, theme, payment and shipping setup, catalog, pages, email, first test order) |

## Order of work

6.1 → 6.2 → 6.3 (exit criteria first), then 6.5, 6.6, 6.4, 6.7, 6.8. Each milestone lands with
tests, Larastan/Pint clean and, where the API changes, an additive contract update.
