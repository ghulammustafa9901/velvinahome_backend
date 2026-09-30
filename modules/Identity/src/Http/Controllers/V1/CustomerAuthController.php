<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Identity\Actions\AuthenticateCustomer;
use Modules\Identity\Actions\RegisterCustomer;
use Modules\Identity\Actions\RevokeCustomerToken;
use Modules\Identity\Data\CustomerLoginData;
use Modules\Identity\Data\CustomerRegistrationData;
use Modules\Identity\Data\RequestContextData;
use Modules\Identity\Http\Resources\CustomerResource;
use Modules\Identity\Models\Customer;

/**
 * Customer authentication for the storefront BFF (ADR-0003). Thin: validate into a DTO,
 * call one Action, shape the response.
 */
final class CustomerAuthController
{
    public function register(Request $request, RegisterCustomer $register): JsonResponse
    {
        // The same rules CustomerRegistrationData enforces, stated here for the API contract.
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'accepts_marketing' => ['nullable', 'boolean'],
        ]);

        $customer = $register->handle(CustomerRegistrationData::validateAndCreate($request));

        return CustomerResource::make($customer)->response()->setStatusCode(201);
    }

    public function login(Request $request, AuthenticateCustomer $authenticate): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $authenticate->handle(
            CustomerLoginData::validateAndCreate($request),
            new RequestContextData($request->ip(), $request->userAgent()),
        );

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'customer' => CustomerResource::make($result['customer']),
            ],
        ]);
    }

    public function logout(Request $request, RevokeCustomerToken $revoke): Response
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $revoke->handle($customer);

        return response()->noContent();
    }

    public function me(Request $request): CustomerResource
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return CustomerResource::make($customer);
    }
}
