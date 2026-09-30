<?php

declare(strict_types=1);

namespace Modules\Checkout\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Contracts\Carts;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Checkout\Exceptions\IdempotencyKeyReused;
use Modules\Checkout\Exceptions\PriceChanged;
use Modules\Checkout\Support\OrderAccessToken;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Orders\Contracts\OrderBook;
use Modules\Orders\Data\NewOrder;
use Modules\Orders\Data\NewOrderLine;
use Modules\Payments\Contracts\Payments;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Contracts\Coupons;
use Modules\Pricing\Data\PricedCart;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;
use Modules\Support\Contracts\Action;
use Modules\Support\ValueObjects\Money;

/**
 * Turn a cart into an order, in one transaction (docs/plans/phase-3.md):
 *
 *   reprice → compare expected total (409 price_changed) → reserve every line
 *   (409 stock_unavailable) → redeem coupon under lock → record order → open payment →
 *   COD: commit stock (ADR-0010) → close cart → outbox `order.placed`
 *
 * Any failure rolls everything back: no half-placed order, no stranded reservation, no
 * coupon use without an order. `Idempotency-Key` makes retries safe: the same key and body
 * return the original order; the same key with a different body is refused.
 */
final class PlaceOrder implements Action
{
    public function __construct(
        private readonly Carts $carts,
        private readonly CartPricer $pricer,
        private readonly StockReservations $stock,
        private readonly Coupons $coupons,
        private readonly OrderBook $orders,
        private readonly Payments $payments,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{order_id: string, number: string, access_token: string, replayed: bool}
     */
    public function handle(PlaceOrderInput $input): array
    {
        $fingerprint = $input->fingerprint();

        if (($replay = $this->replay($input->idempotencyKey, $fingerprint)) !== null) {
            return $replay;
        }

        try {
            return $this->db->connection()->transaction(fn (): array => $this->place($input, $fingerprint));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same key won the race; answer as it did.
            return $this->replay($input->idempotencyKey, $fingerprint) ?? throw $exception;
        }
    }

    /**
     * Reads the database, so two calls can differ (a concurrent request may commit between).
     *
     * @return array{order_id: string, number: string, access_token: string, replayed: bool}|null
     *
     * @phpstan-impure
     */
    private function replay(string $key, string $fingerprint): ?array
    {
        $existing = $this->orders->findByIdempotencyKey($key);

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing['request_hash'], $fingerprint)) {
            throw IdempotencyKeyReused::make();
        }

        return [
            'order_id' => $existing['ulid'],
            'number' => $existing['number'],
            'access_token' => OrderAccessToken::for($existing['ulid']),
            'replayed' => true,
        ];
    }

    /**
     * @return array{order_id: string, number: string, access_token: string, replayed: bool}
     */
    private function place(PlaceOrderInput $input, string $fingerprint): array
    {
        $cart = $this->carts->accessible($input->cartUlid, $input->customerId, $input->cartToken);

        if ($cart->lines === []) {
            throw ValidationException::withMessages(['cart_id' => __('Your cart is empty.')]);
        }

        if (! $this->payments->isEnabled($input->paymentMethodCode)) {
            throw ValidationException::withMessages(['payment_method' => __('This payment method is not available.')]);
        }

        $priced = $this->pricer->price(new PricingRequest(
            lines: $cart->lines,
            currency: $cart->currency,
            couponCode: $cart->couponCode,
            customerId: $input->customerId,
            email: $input->email,
            shippingAddress: $input->shippingAddress,
            shippingMethodCode: $input->shippingMethodCode,
        ));

        $this->assertPlaceable($priced, $input);

        $orderUlid = (string) Str::ulid();
        $reservations = [];

        // Reserved against the order, not the cart: order reservations never expire
        // (ADR-0010), so the TTL is irrelevant here. Reserved in variant order, so two
        // checkouts sharing variants lock stock rows in the same order and cannot deadlock.
        $byVariant = $priced->lines;
        usort($byVariant, fn (PricedLine $a, PricedLine $b): int => strcmp($a->variant->ulid, $b->variant->ulid));

        foreach ($byVariant as $line) {
            $reservations[$line->variant->ulid] = $this->stock->reserve($line->variant->ulid, $line->quantity, 'order', $orderUlid, 0);
        }

        if ($priced->couponCode !== null && ($priced->discount->isPositive() || $priced->freeShipping)) {
            $this->coupons->redeem($priced->couponCode, $orderUlid, $input->customerId, $input->email, $priced->discount);
        }

        $cod = $input->paymentMethodCode === 'cod';
        /** @var \Modules\Shipping\Data\ShippingOptionData $shipping */
        $shipping = $priced->selectedShipping;

        $number = $this->orders->place(new NewOrder(
            ulid: $orderUlid,
            customerId: $input->customerId,
            email: $input->email,
            phone: $input->shippingAddress->phone,
            currency: $cart->currency,
            lines: array_map(fn (PricedLine $line): NewOrderLine => new NewOrderLine(
                variantUlid: $line->variant->ulid,
                productUlid: $line->variant->productUlid,
                sku: $line->variant->sku,
                title: $line->variant->productTitle,
                variantTitle: $line->variant->variantTitle,
                options: $line->variant->optionValues,
                imageUrl: $line->variant->imageUrl,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                subtotal: $line->subtotal,
                discount: $line->discount,
                tax: $line->tax,
                taxRateBps: $line->taxRateBps,
                total: $line->total,
                reservationUlid: $reservations[$line->variant->ulid],
            ), $priced->lines),
            subtotal: $priced->subtotal,
            discount: $priced->discount,
            shipping: $priced->shipping,
            tax: $priced->tax,
            total: $priced->total,
            taxInclusive: $priced->taxInclusive,
            shippingAddress: $input->shippingAddress,
            billingAddress: $input->billingAddress,
            shippingMethodCode: $shipping->code,
            shippingMethodName: $shipping->name,
            paymentMethodCode: $input->paymentMethodCode,
            couponCode: $priced->couponCode,
            customerNote: $input->note,
            initialStatus: $cod ? NewOrder::STATUS_AWAITING_CONFIRMATION : NewOrder::STATUS_PENDING_PAYMENT,
            idempotencyKey: $input->idempotencyKey,
            requestHash: $fingerprint,
            accessTokenHash: OrderAccessToken::hash(OrderAccessToken::for($orderUlid)),
            ipAddress: $input->ipAddress,
            promotions: array_map(fn (array $promotion): array => ['name' => $promotion['name'], 'amount' => $promotion['amount']->amount], $priced->promotions),
        ));

        $this->payments->open($orderUlid, $input->paymentMethodCode, $priced->total, $input->idempotencyKey);

        // COD: the sale is committed at placement; stock comes back only through an explicit
        // cancellation or RTO (ADR-0010). Prepaid orders keep a reservation until paid.
        if ($cod) {
            foreach ($reservations as $reservationUlid) {
                $this->stock->commit($reservationUlid, $orderUlid);
            }
        }

        $this->carts->markConverted($cart->ulid, $orderUlid);

        return [
            'order_id' => $orderUlid,
            'number' => $number,
            'access_token' => OrderAccessToken::for($orderUlid),
            'replayed' => false,
        ];
    }

    private function assertPlaceable(PricedCart $priced, PlaceOrderInput $input): void
    {
        if ($priced->problems !== []) {
            throw ValidationException::withMessages(['cart_id' => __('Some items in your cart are no longer available.')]);
        }

        if ($priced->couponCode !== null && $priced->couponError !== null) {
            throw ValidationException::withMessages(['coupon' => $priced->couponError]);
        }

        if ($priced->selectedShipping === null) {
            throw ValidationException::withMessages(['shipping_method' => __('This shipping method is not available for your address.')]);
        }

        if ($input->paymentMethodCode === 'cod' && ! $priced->selectedShipping->codAllowed) {
            throw ValidationException::withMessages(['payment_method' => __('Cash on Delivery is not available for this shipping method.')]);
        }

        if ($priced->total->amount !== $input->expectedTotal) {
            throw PriceChanged::between(Money::of($input->expectedTotal, $priced->total->currency), $priced->total);
        }
    }
}
