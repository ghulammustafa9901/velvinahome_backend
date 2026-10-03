# Phase 3 Checkpoint — Commerce Core

> **Historical record. Superseded in part by [plans/remove-multi-tenancy.md](plans/remove-multi-tenancy.md) and
> [ADR-0020](adr/0020-single-store-application-without-multi-tenancy.md) (2026-10-03):** the application is now single-store — tenants,
> the landlord database, the platform panel and isolation tests described here no longer exist.


**Date:** 2026-09-27 · **Status:** built; Phase 4 follows on the owner's instruction
Plan: [plans/phase-3.md](plans/phase-3.md) · Decisions: [ADR-0015](adr/0015-order-payment-and-checkout-mechanics.md)

**Owner decisions for this phase:** payments at launch are **Cash on Delivery and manual
bank/wallet transfer**; the transactional email provider is still open (mail goes through
Laravel's mailer, so it is configuration).

## 1. Exit criteria (spec §13, adjusted to the owner's payment decision)

| Criterion | Result |
|---|---|
| A customer completes checkout via COD through the API | **Met.** Guest and signed-in carts → quote → `POST /checkout/orders` with `Idempotency-Key`; order, snapshots, payment attempt, stock commitment and confirmation email, proven end to end. |
| …and via the online gateway sandbox | **Replaced by manual transfer** (owner decision). Transfer orders: pay instructions → receipt upload → staff verify / reject, proven end to end. The gateway adapter contract and webhook intake (raw storage, dedupe) exist; every provider answers 404 until an adapter is registered. |
| Stock, payments and order states stay consistent under failure and retry | **Met.** Placement is one transaction; stale totals (`409 price_changed`), sold-out stock (`409 stock_unavailable`), coupon limits (`409 coupon_unavailable`) and unknown payment methods leave nothing behind; idempotent replay returns the same order; key reuse with a different body is refused; refunds are idempotent and capped by a DB constraint; the order timeline and payment ledger are append-only at the database level. |
| Staff can confirm, fulfil, cancel and refund orders | **Met.** Admin order page: COD confirmation call (confirmed / unreachable / fake), verify / reject transfer, start processing, ship (partial or full, courier + tracking), delivered / RTO, complete, cancel, customer return (restock or not), refund, staff notes — each button runs an Action that re-checks the permission. |

**Test suite:** 269 passing (1 skipped), up from 223 — 46 new tests for this phase (pricing,
carts, checkout, order lifecycle, admin, payments API, commerce isolation). All on real
PostgreSQL 17, Redis and Typesense 30. Larastan level 8 and Pint clean.

## 2. What was built

| Module | Owns | Public surface |
|---|---|---|
| Tax | `tax_rates` (basis points; global / country / province) | `TaxCalculator` (inclusive or exclusive per store setting) |
| Customers | `customer_addresses` | `AddressBook`, `AddressData`; `/account/addresses` API |
| Shipping | zones (country / province / city lists), methods, rates | `ShippingRates`; default "Nationwide, Rs 250, free over Rs 5,000, COD allowed" seeded per brand |
| Pricing | `coupons`, `coupon_redemptions` | `CartPricer` (lines → coupon → shipping → tax → totals), `Coupons` (redeem under row lock, void) |
| Cart | `carts`, `cart_items` | `Carts`; guest token (hashed), customer carts, merge on sign-in, `/carts` API |
| Payments | `payment_methods`, `payments`, `payment_transactions` (ledger), `webhook_events` | `Payments`, `PaymentGateway`; `/payment-methods`, `/webhooks/{provider}` |
| Orders | `orders`, `order_items`, `order_addresses`, `order_state_transitions` (ledger), `order_notes`, `shipments`, `order_returns` | `OrderBook`; `/orders/{id}` (guest token or owner), `/account/orders`, receipt upload |
| Checkout | — (orchestration) | `/checkout/quote`, `/checkout/orders` |
| Notifications | `notification_templates`, `notification_deliveries` | outbox handlers for `order.placed/confirmed/shipped/delivered/cancelled/refunded/returned` |

**Deviation from the module map** ([architecture/modules.md](architecture/modules.md)):
shipments are recorded in **Orders** rather than Shipping, because in v1 a shipment is only
"which items left, with which courier and tracking number". When courier adapters arrive
(Phase 6) shipments move behind a Shipping contract; the tables are self-contained so the move
is mechanical.

Admin screens: Orders, Coupons, Shipping, Tax rates, Payment methods, Customer emails.
Scheduler: unpaid transfer orders are cancelled after the brand's payment window (48 h default).

## 3. Found and fixed along the way

1. A cancellation helper exposed a second public method on an Action — moved to `OrderCanceller`
   (architecture test).
2. The webhook Action took an HTTP Request — replaced by an `InboundWebhook` DTO that keeps the
   raw body for signature checks (architecture test).
3. Notifications registered its outbox handlers through another module's internals — the
   registry is now behind an `OutboxHandlers` contract (boundary test).
4. Test-run tenant files under `storage/tenant_*` had been committed in earlier phases — now
   ignored and removed from the repository.

## 4. Deferred / known gaps

- Online gateway adapter (PayFast default), webhook processing job and payment reconciliation
  job — when the owner picks a gateway.
- Courier adapters and automatic shipment tracking (spec §6.7) — Phase 6; shipments are recorded
  manually with courier + tracking number.
- Coupon product/category scoping is supported by the Action and pricing but not editable in
  the admin form yet.
- Customer-initiated returns (RMA requests) — staff record returns; self-service later.
- Order emails do not yet link to a storefront order page (storefront arrives in Phase 5).
- Checkout race is covered by the Phase 2 multi-process reservation test at the stock layer;
  no separate multi-process checkout test.

## 5. Open questions (spec §16)

- Transactional email provider (SES / Postmark / Resend) — needed before launch.
- First online payment gateway — deferred by the owner.
- Platform name/domain, VOG/SIX, topology — unchanged.
