<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Actions\ApplyCartCoupon;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\MergeGuestCart;
use Modules\Cart\Actions\RecoverCart;
use Modules\Cart\Actions\SetCartItem;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartItem;
use Modules\Cart\Support\CartAccess;
use Modules\Inventory\Contracts\StockReader;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\PricedLine;
use Modules\Pricing\Data\PricingRequest;

/**
 * Carts for guests (secret `X-Cart-Token`) and customers (bearer token). Every response is
 * the cart priced afresh by the pipeline and is never cached.
 */
final class CartController
{
    public const TOKEN_HEADER = 'X-Cart-Token';

    public function __construct(
        private readonly CartAccess $access,
        private readonly CartPricer $pricer,
        private readonly StockReader $stock,
    ) {}

    public function store(Request $request, CreateCart $create): JsonResponse
    {
        $result = $create->handle(self::customerId($request));

        return $this->respond($result['cart'], 201, $result['token']);
    }

    /**
     * Reopen a guest cart from an abandoned-cart reminder. Answers like cart creation: the
     * response carries a fresh cart token for this browser (the old one stops working).
     */
    public function recover(Request $request, string $ulid, RecoverCart $recover): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $result = $recover->handle($ulid, (string) $data['token']);

        return $this->respond($result['cart'], 200, $result['token']);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        return $this->respond($this->cart($request, $ulid));
    }

    public function addItem(Request $request, string $ulid, SetCartItem $set): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.SetCartItem::MAX_QUANTITY],
        ]);

        return $this->respond($set->handle($this->cart($request, $ulid), (string) $data['variant_id'], (int) $data['quantity']));
    }

    public function updateItem(Request $request, string $ulid, string $itemUlid, SetCartItem $set): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.SetCartItem::MAX_QUANTITY]]);
        $cart = $this->cart($request, $ulid);
        $item = CartItem::query()->where('cart_id', $cart->id)->where('ulid', $itemUlid)->firstOrFail();

        return $this->respond($set->handle($cart, $item->variant_ulid, (int) $data['quantity'], 'set'));
    }

    public function removeItem(Request $request, string $ulid, string $itemUlid, SetCartItem $set): JsonResponse
    {
        $cart = $this->cart($request, $ulid);
        $item = CartItem::query()->where('cart_id', $cart->id)->where('ulid', $itemUlid)->firstOrFail();

        return $this->respond($set->handle($cart, $item->variant_ulid, 0, 'set'));
    }

    public function applyCoupon(Request $request, string $ulid, ApplyCartCoupon $apply): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50'], 'email' => ['nullable', 'email']]);

        return $this->respond($apply->handle($this->cart($request, $ulid), (string) $data['code'], $data['email'] ?? null));
    }

    public function removeCoupon(Request $request, string $ulid, ApplyCartCoupon $apply): JsonResponse
    {
        return $this->respond($apply->handle($this->cart($request, $ulid), null));
    }

    /**
     * Signed-in customer adopts a guest cart they can prove with its token.
     */
    public function merge(Request $request, string $ulid, MergeGuestCart $merge): JsonResponse
    {
        $customerId = self::customerId($request);
        abort_if($customerId === null, 401);

        $guest = $this->access->find($ulid, null, $request->header(self::TOKEN_HEADER));

        return $this->respond($merge->handle($guest, $customerId));
    }

    private function cart(Request $request, string $ulid): Cart
    {
        return $this->access->find($ulid, self::customerId($request), $request->header(self::TOKEN_HEADER));
    }

    private function respond(Cart $cart, int $status = 200, ?string $token = null): JsonResponse
    {
        $cart->loadMissing('items');
        $priced = $this->pricer->price(new PricingRequest($cart->lines(), $cart->currency, $cart->coupon_code, $cart->customer_id));
        $stock = $this->stock->available(array_map(fn (PricedLine $line): int => $line->variant->id, $priced->lines));

        /** @var array<string, string> $lineIds */
        $lineIds = $cart->items->pluck('ulid', 'variant_ulid')->all();
        $available = [];

        foreach ($priced->lines as $line) {
            $available[$line->variant->ulid] = $line->variant->allowBackorder ? null : ($stock[$line->variant->id] ?? 0);
        }

        $body = $priced->toApi($lineIds, $available);

        return response()->json(['data' => [
            'id' => $cart->ulid,
            'currency' => $cart->currency,
            'item_count' => array_sum($cart->lines()),
            // The guest cart secret: present only in the response that created the cart.
            'token' => $token,
            'lines' => $body['lines'],
            'totals' => $body['totals'],
            'coupon' => $body['coupon'],
            'promotions' => $body['promotions'],
            'shipping_options' => $body['shipping_options'],
            'selected_shipping' => $body['selected_shipping'],
            'problems' => $body['problems'],
        ]], $status);
    }

    /**
     * The cart API is public; a bearer token, when present, identifies the customer.
     */
    private static function customerId(Request $request): ?int
    {
        $user = $request->user('customer');

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }
}
