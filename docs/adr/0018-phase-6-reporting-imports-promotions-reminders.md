# ADR-0018: Reporting, imports, promotions and reminders (Phase 6 mechanics)

- **Status:** Accepted
- **Date:** 2026-10-01
- **Spec reference:** §6.4, §6.7, §11, §13 (Phase 6), ADR-0007, ADR-0008, ADR-0015

## Context

Phase 6 adds operational modules that read or write across many modules (reports over orders
and refunds, importers that create products, customers and redirects) while ADR-0007 forbids
one module touching another's models. Its exit criteria are concrete: *reports match raw data*
and *a WooCommerce store can be imported with redirects*.

## Decision

### Reporting: rebuild whole days, prove them

- Orders publishes `SalesLedger`, Payments publishes `RefundLedger` (SQL aggregates per
  brand-local day and currency). Reporting stores `report_sales_days` / `report_product_days`
  and **rebuilds a day whole** (delete + insert in one transaction) — never increments, so any
  rebuild converges on the truth.
- Which days: those with orders updated / refunds recorded after a watermark, with a 5-minute
  look-back for late commits. Not events: the outbox maps an event to one handler, and a
  watermark self-heals after downtime.
- `reports:verify` recomputes from the ledgers and compares every column; non-zero exit on any
  difference. Cancelled orders count as placed but not in sales; refunds count on the day they
  were recorded.

### Imports: through the owners' contracts

- The `Imports` module writes only through narrow contracts each owner implements with its
  own Actions: `CatalogImport`, `StockImport` (opening stock as a stock count, so the ledger
  explains it), `CustomerImport`, `AddressImport`, and `Seo::recordMove` for 301s.
- Runs are queued, one per brand (advisory lock), with dry run, per-record issue report, and
  external-id mappings so re-runs update. Source credentials are encrypted on the run and
  erased when it ends.
- Outbound requests (store URL from staff, image URLs from the store): HTTPS, public addresses
  only, no redirects followed, 10 MB image cap. DNS rebinding between check and request is not
  covered; acceptable for staff-initiated, low-frequency runs.
- Historical orders are an archive (`imported_orders`), not live orders: no stock, payments
  or reports derive from them.
- Decimal strings become minor units by string arithmetic (no floats, ADR-0008); a store in
  another currency is refused.

### Promotions: before coupons, one per item

- Automatic promotions (percentage sale, spend tiers, buy X get Y) run in priority order;
  **each line can be discounted by one promotion only**; order-level amounts are spread with
  `Money::allocate`. A coupon then applies to what promotions left, never to discounted money.
- Applied promotions are part of quotes (`promotions`, additive) and recorded on the order.

### Abandoned-cart reminders and couriers

- One reminder per guest cart with a checkout email (feature `abandoned_cart`, off by
  default). The recovery secret is **derived** (HMAC of cart + reminder time, 7 days), never
  stored; using it rotates the cart token for the clicking browser. Unsubscribing needs a
  signed link and a click; suppression stores only a hash of the address.
- Couriers: brand rows with an adapter key and an https tracking-link template; the
  `CourierAdapter` contract and an hourly tracking sync exist, only the manual courier is
  implemented until the owner has a courier account (owner decision, 2026-09-30).

## Consequences

- Reports lag orders by at most the refresh interval (5 minutes); dashboards say so.
- Import contracts are a small, reviewed write surface into other modules instead of shared
  models; adding an import source does not widen it.
- Promotions never stack on an item, which keeps margins predictable but means a merchant
  cannot combine two automatic offers on one product (by design for v1).
- Courier automation and online payment adapters remain open until the owner chooses
  providers.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Incrementing report counters from events | Drifts on retries and missed events; cannot be proven against raw data |
| Reading other modules' tables from Reporting/Imports | Breaks ADR-0007 boundaries and couples schemas |
| Importing historical orders as live orders | Would move stock, create payments and distort reports |
| Storing cart recovery tokens | One more secret at rest; derivation gives the same with nothing to leak |
