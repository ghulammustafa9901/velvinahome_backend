# Phase 3 Plan — Commerce Core

**Owner decision (2026-09-27):** payment methods for launch are **Cash on Delivery and manual
bank/wallet transfer**. The `PaymentGateway` contract, webhook intake and reconciliation are
built so an online gateway (PayFast by default) is an adapter later, not a redesign. The
transactional email provider stays undecided: mail goes through Laravel's mailer (log /
Mailpit locally), so choosing SES / Postmark / Resend is configuration.

**Exit criteria (spec §13):** a customer completes checkout via COD (and manual transfer)
through the API; stock, payments and order states stay consistent under failure and retry;
staff can confirm, fulfil, cancel and refund orders.

## Modules and contracts

| Module | Owns | Publishes |
|---|---|---|
| Tax | `tax_rates` | `TaxCalculator` (inclusive/exclusive from settings, basis points) |
| Shipping | zones, methods, rates | `ShippingRates` (options for an address, weight, subtotal) |
| Customers | `customer_addresses` | `AddressBook`, `AddressData` |
| Pricing | `coupons`, `coupon_redemptions` | `CartPricer` (the pipeline), `Coupons` |
| Cart | `carts`, `cart_items` | `Carts` (load with access check, mark converted) |
| Payments | `payment_methods`, `payments`, `payment_transactions` (ledger), `webhook_events` | `PaymentMethods`, `Payments`, `PaymentGateway` (adapter contract); events `PaymentConfirmed`, `PaymentFailed` |
| Orders | `orders`, `order_items` (snapshots), `order_addresses`, `order_state_transitions` (ledger), `shipments`, `returns` | `OrderBook`; outbox events `order.*` |
| Checkout | — (orchestration only) | quote and place-order Actions + API |
| Notifications | `notification_templates` | outbox handlers for `order.*` |

Catalog gains `VariantCatalog::sellable()`; Inventory gains `StockReservations`
(reserve / commit / release / restock).

## Pricing pipeline (spec §6.4)

`lines → coupon → shipping → tax → totals`, integer minor units throughout, recomputed
server-side on every cart read, quote and order placement. Discounts are allocated across
lines with `Money::allocate` so line totals always sum to the order total.

## Order placement (one transaction)

recompute quote → compare `expected_total` (→ `409 price_changed`) → reserve every line
(→ `409 stock_unavailable`, nothing written) → redeem coupon under row lock → create order,
item snapshots, addresses, first transition → open payment → COD: commit reservations
(ADR-0010) → mark cart converted → outbox `order.placed`. `Idempotency-Key` is required and
unique per tenant: same key + same body replays the original order; different body → `409`.

## Order state machine (spatie/laravel-model-states)

`pending_payment → confirmed` (transfer verified) · `awaiting_confirmation → confirmed`
(COD call) · `confirmed → processing → partially_fulfilled → fulfilled → delivered →
completed` · cancellable before fulfilment · `delivered → returned / refunded /
partially_refunded`. Every transition is appended to `order_state_transitions`.

## Tests (highlights)

Pricing (inclusive/exclusive tax, coupons with limits, allocation), cart access (guest token,
IDOR), merge on login, checkout COD end to end, manual transfer verify/reject, idempotent
replay and key reuse, price change, stock race at checkout, cancellation paths restock
(ADR-0010), state machine rejects illegal transitions, emails sent after commit only,
isolation of orders and carts between sibling brands.
