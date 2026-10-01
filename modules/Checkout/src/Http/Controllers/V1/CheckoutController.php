<?php

declare(strict_types=1);

namespace Modules\Checkout\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Contracts\Carts;
use Modules\Checkout\Actions\PlaceOrder;
use Modules\Checkout\Actions\RememberCheckoutEmail;
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

    public function quote(Request $request, Carts $carts, CartPricer $pricer, Payments $payments, RememberCheckoutEmail $remember): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'string', 'size:26'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.first_name' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.last_name' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.phone' => ['required_with:shipping_address', 'string', 'max:20'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.province' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country_code' => ['nullable', 'string', 'size:2'],
            'shipping_method' => ['nullable', 'string', 'max:32'],
        ]);

        $customerId = self::customerId($request);
        $cart = $carts->accessible((string) $data['cart_id'], $customerId, $request->header(self::CART_TOKEN_HEADER));
        $address = isset($data['shipping_address']) ? self::address($data['shipping_address'], 'shipping_address') : null;
        $remember->handle($cart->ulid, $customerId, $data['email'] ?? null);

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

        $body = $priced->toApi();
        $paymentMethods = [];

        foreach ($methods as $method) {
            $paymentMethods[] = ['code' => $method['code'], 'label' => $method['label'], 'description' => $method['description']];
        }

        return response()->json(['data' => [
            'cart_id' => $cart->ulid,
            'currency' => $cart->currency,
            'lines' => $body['lines'],
            'totals' => $body['totals'],
            'coupon' => $body['coupon'],
            'promotions' => $body['promotions'],
            'shipping_options' => $body['shipping_options'],
            'selected_shipping' => $body['selected_shipping'],
            'problems' => $body['problems'],
            'payment_methods' => $paymentMethods,
        ]]);
    }

    /**
     * Delivery options for a cart and destination, priced for this cart (weight, value,
     * free-shipping coupons) before the customer has typed a full address.
     */
    public function shippingMethods(Request $request, string $cart, Carts $carts, CartPricer $pricer): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['nullable', 'string', 'size:2'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
        ]);

        $customerId = self::customerId($request);
        $found = $carts->accessible($cart, $customerId, $request->header(self::CART_TOKEN_HEADER));

        // Only the destination matters for zone matching; the rest of the address comes later.
        $destination = new AddressData(
            firstName: '',
            lastName: '',
            phone: '',
            line1: '',
            city: (string) $data['city'],
            province: $data['province'] ?? null,
            countryCode: strtoupper((string) ($data['country_code'] ?? 'PK')),
        );

        $priced = $pricer->price(new PricingRequest(
            lines: $found->lines,
            currency: $found->currency,
            couponCode: $found->couponCode,
            customerId: $customerId,
            shippingAddress: $destination,
        ));

        return response()->json(['data' => $priced->toApi()['shipping_options']]);
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
            'shipping_address.first_name' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.last_name' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.phone' => ['required_with:shipping_address', 'string', 'max:20'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.province' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country_code' => ['nullable', 'string', 'size:2'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.first_name' => ['required_with:billing_address', 'string', 'max:100'],
            'billing_address.last_name' => ['required_with:billing_address', 'string', 'max:100'],
            'billing_address.phone' => ['required_with:billing_address', 'string', 'max:20'],
            'billing_address.line1' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.line2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:100'],
            'billing_address.province' => ['nullable', 'string', 'max:100'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country_code' => ['nullable', 'string', 'size:2'],
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

        $data = [
            'order_id' => $result['order_id'],
            'number' => $result['number'],
            // Guest order secret for GET /orders/{id} (X-Order-Token); shown again on replays.
            'access_token' => $result['access_token'],
        ];

        // 200 + Idempotent-Replayed: true when this key already placed the order.
        if ($result['replayed']) {
            return response()->json(['data' => $data], 200)->header('Idempotent-Replayed', 'true');
        }

        return response()->json(['data' => $data], 201)->header('Idempotent-Replayed', 'false');
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
