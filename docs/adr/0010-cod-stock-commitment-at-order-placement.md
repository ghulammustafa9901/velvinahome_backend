# ADR-0010: COD orders commit stock at order placement, not at COD confirmation

- **Status:** Accepted
- **Date:** 2026-09-26
- **Spec reference:** §6.5 rule 3 (FINAL design, but internally inconsistent for COD), §6.4, §1.3
- **Decided by:** owner delegated the decision after it was raised at the Phase 0 checkpoint (§3.2)

## Context

Spec §6.5 rule 3 states that reservations are "created at checkout/payment initiation with an
expiry; converted to a sale on payment success **or COD confirmation**; released on failure,
cancellation, or expiry (scheduled sweep)."

That works for online payment, where success or failure arrives in seconds or minutes. It does
not work for Cash on Delivery, which spec §1.3 identifies as **the dominant payment method** in
this market. COD confirmation is a human phone call. It can take hours, or a day, or several
attempts across a weekend.

This produces a direct conflict between two FINAL requirements:

- If the reservation expiry is short enough to protect against abandoned checkouts (say 30
  minutes), it expires while a live, unconfirmed COD order still exists. The sweep releases the
  stock, the same unit is sold again, and **overselling happens** — which §6.5 rule 2 says must
  be impossible.
- If the expiry is long enough to survive confirmation (say 24 hours), then every abandoned
  checkout locks real stock for a day. On a small catalog with a popular item, a handful of
  abandoned carts makes a product unbuyable.

There is no expiry value that satisfies both. The model itself has to change for COD.

## Decision

**Separate the two states that §6.5 rule 3 conflates.** A reservation protects a *checkout in
progress*. A placed order is not a checkout in progress — it is a commitment.

| Moment | Online payment | COD |
|---|---|---|
| Checkout / payment initiation | `stock_reservations` row, short expiry (default 30 min, a tenant setting) | same |
| Order placed | reservation held until the gateway confirms | **reservation converted immediately to a `sale` movement** in the same transaction that creates the order |
| Payment confirmed (verified webhook) | reservation converted to a `sale` movement | nothing further — stock already committed |
| Payment failed / expired | reservation released | not applicable |
| COD confirmed by staff | not applicable | nothing further — stock already committed |
| COD marked `unreachable` or `fake`, or order cancelled | order cancelled, reservation released | **`return` movement** with the cancellation reason, putting stock back |
| RTO (returned to origin) | `return` movement on receipt | `return` movement on receipt, with `rto` reason |

So for COD, the order of events becomes: reserve at checkout → **commit at placement** →
release by an explicit return movement if the order dies.

Concretely:

- `PlaceOrder` takes a `payment_method` and asks the `Inventory` contract to either hold the
  reservation (online) or convert it (COD), inside the same database transaction as the order
  insert. There is no window in which an order exists without its stock accounted for.
- The reservation expiry sweep only ever touches reservations with no order attached, or
  reservations belonging to an order still in `pending_payment`. It can never release stock
  behind a live COD order, and a test asserts exactly that.
- `CancelOrder` and the COD outcomes `unreachable` / `fake` write an `inventory_movements` row
  of type `return` with a mandatory reason, through the normal Inventory Action. Stock never
  reappears silently.

## Consequences

### Positive

- **Overselling stays impossible for COD**, which is the majority of orders. This was the
  actual risk, and it is closed structurally rather than by tuning a timeout.
- Abandoned checkouts hold stock for minutes, not a day, so the short expiry can stay short.
- The ledger tells the truth about where units are. An RTO or a fake order becomes a visible
  `return` movement with a reason, so "how much stock did fake COD orders cost us this month"
  is a query rather than a guess. In this market that is a genuinely useful number, and under
  the original model it would have been invisible — the stock would simply have drifted back
  via an expiry sweep with no business reason attached.
- `available = on_hand − reserved` stays meaningful: `reserved` means "someone is checking out
  right now", not "someone might confirm tomorrow".

### Negative / costs

- `on_hand` drops at order placement, before any money exists. For COD that is the honest
  reading — the unit is promised and will be dispatched — but it means the stock report and the
  paid-revenue report move at different moments, and staff need to understand that. Documented
  in the admin and in the onboarding checklist.
- Cancellation and RTO paths **must** write return movements. If one is missed, stock is
  permanently understated. Mitigated by making cancellation only reachable through
  `CancelOrder`, by the nightly reconciliation job, and by a test per cancellation path.
- A deliberate divergence from the literal wording of a FINAL spec rule, which is why it is
  recorded here rather than absorbed silently.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| A cancellation path forgets the return movement | Every order-cancelling path goes through `CancelOrder`; a test enumerates the cancellation reasons and asserts a movement for each; nightly reconciliation catches a drift |
| Staff confusion: "stock dropped but we were not paid" | The admin shows committed-but-unconfirmed COD quantity separately on the stock screen; covered in onboarding |
| A high fake-order rate silently eats availability | Fake/unreachable outcomes are reported as an inventory event with a reason, so the rate is measurable and actionable — the point of doing it this way |
| Double release (expiry sweep *and* cancellation) | Reservations move through a state machine; `converted` and `released` are terminal; the sweep only selects `active` reservations with no live order |

## Alternatives considered

| Alternative | Why not |
|---|---|
| Literal spec reading: convert at COD confirmation, keep a long expiry | Abandoned carts lock stock for a day, and the expiry sweep can still fire behind a live order. It is the option that actually oversells. |
| Convert at confirmation, but make an order's reservation non-expiring | This is the chosen decision wearing a disguise: a non-expiring reservation *is* a commitment. Naming it a `sale` movement keeps the ledger honest instead of hiding committed stock in `reserved`. |
| Allow backorders for COD instead | Shifts the problem to the customer: they buy, then wait, then get cancelled. Unacceptable default for a market where COD trust is already the constraint. |
| A separate `committed` column alongside `on_hand` and `reserved` | A third quantity to keep consistent, and it breaks the rule that `on_hand` moves only with a ledger movement. The admin can *display* committed-unconfirmed COD from order state without storing a fourth number. |
