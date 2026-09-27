<?php

declare(strict_types=1);

namespace Modules\Checkout\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Contracts\Carts;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Data\PlaceOrderInput;
use Modules\Customers\Data\AddressData;
use Modules\Payments\Contracts\Payments;
use Modules\Pricing\Contracts\CartPricer;
use Modules\Pricing\Data\PricingRequest;

/**
 * Quote (price the cart for an address and shipping method) and place the order. Guests
 * prove the cart with `X-Cart-Token`; customers with their bearer token.
 */
final class CheckoutController
{
    private const CART_TOKEN_HEADER = 'X-Cart-Token';

    public function quote(Request $request, Carts $carts, CartPricer $pricer, Payments $payments): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'string', 'size:26'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_method' => ['nullable', 'string', 'max:32'],
        ]);

        $customerId = self::customerId($request);
        $cart = $carts->accessible((string) $data['cart_id'], $customerId, $request->header(self::CART_TOKEN_HEADER));
        $address = isset($data['shipping_address']) ? self::address($data['shipping_address'], 'shipping_address') : null;

        $priced = $pricer->price(new PricingRequest(
            lines: $cart->lines,
            currency: $cart->currency,
            couponCode: $cart->couponCode,
            customerId: $customerId,
            email: $data['email'] ?? self::customerEmail($request),
            shippingAddress: $address,
            shippingMethodCode: $data['shipping_method'] ?? null,
        ));

        $methods = $payments->enabledMethods();

        // COD only where the chosen shipping method allows it.
        if ($priced->selectedShipping !== null && ! $priced->selectedShipping->codAllowed) {
            $methods = array_values(array_filter($methods, fn (array $method): bool => $method['code'] !== 'cod'));
        }

        return response()->json(['data' => ['cart_id' => $cart->ulid, 'currency' => $cart->currency] + $priced->toApi() + [
            'payment_methods' => array_map(fn (array $method): array => [
                'code' => $method['code'],
                'label' => $method['label'],
                'description' => $method['description'],
            ], $methods),
        ]])->header('Cache-Control', 'no-store');
    }

    public function place(Request $request, PlaceOrder $place): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if (preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => __('Send a unique Idempotency-Key header (16–100 letters, digits, - or _) with every order.')]);
        }

        $data = $request->validate([
            'cart_id' => ['required', 'string', 'size:26'],
            'email' => [self::customerId($request) === null ? 'required' : 'nullable', 'email:rfc', 'max:255'],
            'shipping_address' => ['required', 'array'],
            'billing_address' => ['nullable', 'array'],
            'shipping_method' => ['required', 'string', 'max:32'],
            'payment_method' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_total' => ['required', 'integer', 'min:0'],
        ]);

        $email = $data['email'] ?? self::customerEmail($request);

        if ($email === null) {
            throw ValidationException::withMessages(['email' => __('An email address is required.')]);
        }

        $result = $place->handle(new PlaceOrderInput(
            cartUlid: (string) $data['cart_id'],
            cartToken: $request->header(self::CART_TOKEN_HEADER),
            customerId: self::customerId($request),
            email: (string) $email,
            shippingAddress: self::address($data['shipping_address'], 'shipping_address'),
            billingAddress: isset($data['billing_address']) ? self::address($data['billing_address'], 'billing_address') : null,
            shippingMethodCode: (string) $data['shipping_method'],
            paymentMethodCode: (string) $data['payment_method'],
            note: $data['note'] ?? null,
            expectedTotal: (int) $data['expected_total'],
            idempotencyKey: $key,
            ipAddress: $request->ip(),
        ));

        return response()->json(['data' => [
            'order_id' => $result['order_id'],
            'number' => $result['number'],
            'access_token' => $result['access_token'],
        ]], $result['replayed'] ? 200 : 201)
            ->header('Cache-Control', 'no-store')
            ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function address(array $input, string $field): AddressData
    {
        try {
            return AddressData::validateAndCreate($input);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[$field.'.'.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private static function customerId(Request $request): ?int
    {
        $user = $request->user('customer');

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }

    private static function customerEmail(Request $request): ?string
    {
        $email = $request->user('customer')?->getAttribute('email');

        return is_string($email) ? $email : null;
    }
}
