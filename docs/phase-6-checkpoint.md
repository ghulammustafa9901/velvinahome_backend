# Phase 6 Checkpoint — Operations & Growth Modules + Migration Tools

> **Historical record. Superseded in part by [plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md) and
> [ADR-0020](adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Date:** 2026-10-01 · **Status:** built
Plan: [plans/phase-6.md](plans/phase-6.md) · Decisions: [ADR-0018](adr/0018-phase-6-reporting-imports-promotions-reminders.md)

## 1. Exit criteria

| Criterion | Result |
|---|---|
| A real tenant's WooCommerce data can be imported with redirects | **Met against the WooCommerce REST API v3 shapes** (faked store in tests): categories (tree), simple and variable products with prices, sale prices, weights, tags, stock and images, customers with addresses, order history, and a 301 from every old product and category URL. Dry run, re-run without duplicates, currency mismatch refused, SSRF guard. *Not yet run against a live store* — the first real migration (Phase 8) is the final proof; do a dry run first. |
| Reports match raw data | **Met.** Aggregates are rebuilt per brand-local day from the order and refund records; `reports:verify` recomputes and compares every column and fails on any difference. Verified on the dev brands and by tests (including a tampered aggregate being caught). |

**Test suites:** platform-api 360 passing (1 skipped); Larastan level 8 and Pint clean;
`api:contract --check` passes (additive changes only). platform-web: ESLint, strict
typecheck, Vitest, `next build`, Playwright E2E.

## 2. Milestones

| # | Milestone | Result |
|---|---|---|
| 6.1 | Reporting | `Reporting` module; Orders `SalesLedger`, Payments `RefundLedger`; refresh every 5 minutes per brand; `reports:refresh`, `reports:verify`; admin dashboard (sales today / 30 days, average order, COD confirmation rate, sales chart, best sellers) and sales report with CSV export (`reports.view`) |
| 6.2 | WooCommerce importer | `Imports` module: queued runs with progress, dry run, issue report, mappings; owners' import contracts (`CatalogImport`, `StockImport`, `CustomerImport`, `AddressImport`) |
| 6.3 | CSV importers | Products (one row per variant, grouped by handle) and stock counts by SKU |
| 6.4 | Blog and custom content types | Articles (feature `blog`) with sitemap, 301 on move, storefront `/blog` with Article JSON-LD; staff-defined content types with allow-listed field kinds; `GET /v1/articles`, `/v1/content/{type}` |
| 6.5 | Additional promotions | Percentage sale, spend tiers, buy X get Y; one per item, before coupons; shown in cart/checkout and recorded on orders |
| 6.6 | Abandoned-cart notifications | Feature `abandoned_cart`; one reminder, re-checked at send time; derived recovery secret; signed unsubscribe; storefront `/cart/recover`, `/unsubscribe` |
| 6.7 | Courier framework | Couriers with tracking-link templates, `CourierAdapter` contract and registry, hourly tracking sync, `tracking_url` on orders. **Manual courier only — no courier account yet (owner)** |
| 6.8 | Onboarding checklist | Platform panel → Brands → Launch checklist, computed from each brand's real state |
| — | Payment adapters (JazzCash/Easypaisa) | **Deferred (owner decision, 2026-09-30).** The adapter contract and webhook intake exist (ADR-0015) |

Also: the storefront now honours recorded 301s for any unmatched path (a Phase 5 gap, needed
for migrated URLs).

## 3. Found and fixed along the way

1. **Old URLs were not redirected by the storefront** — only product slug changes were. The
   catch-all route now looks up the redirects table (cached, purged on change). Without it the
   importer's redirects would have been invisible.
2. **Feature flags could ignore a plan change**: the plan relation cached on a long-lived
   tenant instance was reused. Flags now read the plan fresh (the result is cached anyway).
3. Storefront route-handler redirects pointed at the internal host after the brand rewrite;
   they now use relative locations.
4. A reporting test picked random past years and eventually collided with earlier runs in the
   shared fixture brand; it now picks a day with no orders.
5. Client-side money formatting crept into the cart totals while adding promotions; replaced
   by an API field (`coupon.discount`) per ADR-0008.

## 4. Deferred / known gaps

- First live WooCommerce migration (Phase 8); variations with "any" attribute values, grouped
  and external products are reported, not imported; brands (WooCommerce 9.6+) not imported.
- Courier API adapter and online payment adapters — waiting on owner choices and credentials.
- Abandoned-cart reminders for signed-in customers' carts (guests only in v1).
- Imported order history is staff-only; showing it in customer accounts is a later addition.
- Reports exclude imported historical orders by design.

## 5. Open questions for the owner

Courier to integrate first (needs an account and API docs/sandbox); online payment gateway
(still deferred); transactional email provider (before launch).
