# ADR-0015: Order, payment and checkout mechanics for launch

- **Status:** accepted
- **Date:** 2026-09-27
- **Context phase:** 3 (Commerce Core)

## Context

The owner chose **Cash on Delivery and manual bank/wallet transfer** as the only payment
methods at launch (online gateways later), and left the transactional email provider
undecided. Phase 3 had to make several mechanical decisions the spec leaves open.

## Decisions

1. **Order states** (spec §5 "YOUR CALL on final list"): `pending_payment`,
   `awaiting_confirmation`, `confirmed`, `processing`, `partially_fulfilled`, `fulfilled`,
   `delivered`, `completed`, `cancelled`, `returned`. Enforced with spatie/laravel-model-states;
   every move goes through one class (`OrderTransitions`) that appends to the append-only
   `order_state_transitions` table. **Refunds are not order states**: payment and order are
   separate machines (spec §6.6); the order carries a `payment_status` mirror for filtering,
   Payments stays the source of truth.
2. **Stock:** COD commits stock at placement, prepaid orders hold an `order` reservation that
   never expires until payment is verified (ADR-0010). A transfer order unpaid after
   `orders.payment_window_hours` (default 48) with no receipt uploaded is cancelled by a
   scheduled job, releasing the stock. Cancellation before shipment releases or restocks;
   RTO and customer returns restock with a reason.
3. **Manual transfer:** the customer uploads a receipt (content-sniffed, ≤ 5 MB, tenant private
   disk); staff verify against their statement. Verification captures the payment and — via the
   synchronous `PaymentConfirmed` event in the same transaction — confirms the order and
   commits stock. Rejection fails the payment and cancels the order. An upload is never
   treated as proof of payment.
4. **Refunds are recorded, not executed:** with no gateway, staff send money back themselves
   and record it. `Payments::refund` never exceeds what was paid (also a CHECK constraint) and
   is idempotent per key; the admin form carries a per-form key so a double submit is one
   refund.
5. **Idempotent placement:** `Idempotency-Key` is required. The order stores the key (unique)
   and a fingerprint of the request; the same key and body replay the original response
   (`200`, `Idempotent-Replayed: true`), a different body is `409 idempotency_key_reused`, and a
   concurrent duplicate loses on the unique index and is answered as a replay.
6. **Guest order access token** is derived — `HMAC-SHA256(APP_KEY, "order-access|" + order ULID)`
   — and only its SHA-256 is stored. Deriving it lets an idempotent replay return the same
   token. Consequence: rotating `APP_KEY` invalidates outstanding guest order links (customers
   with accounts are unaffected). Accepted; the rotation runbook must say so.
7. **Emails** go through the transactional outbox: order events are recorded in the business
   transaction and sent by the outbox publisher after commit using Laravel's mailer, so choosing
   SES/Postmark/Resend later is configuration. Delivery is at-least-once with a per-message
   `notification_deliveries` row, so a redelivered message does not email twice. Staff-written
   reasons (e.g. why an order was cancelled) are never exposed to customer emails.
8. **Money on orders** uses one `currency` column shared by all amounts
   (`MoneyCast::class.':currency'`), and a CHECK constraint that the totals reconcile:
   `total = subtotal − discount + shipping (+ tax when exclusive)`.
9. **Webhooks:** `POST /api/v1/webhooks/{provider}` exists with raw storage and deduplication,
   but with no gateway adapter registered it answers `404` for every provider. The
   `PaymentGateway` contract takes an `InboundWebhook` DTO (raw body + headers), not a Request.

## Consequences

- Adding PayFast (or another gateway) is an adapter + a processing job for stored webhook
  events + a reconciliation job; no order or checkout changes.
- `APP_KEY` rotation needs a note in the secrets-rotation runbook (decision 6).
