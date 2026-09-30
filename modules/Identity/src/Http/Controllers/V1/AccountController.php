<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Actions\ChangeCustomerPassword;
use Modules\Identity\Actions\RequestCustomerPasswordReset;
use Modules\Identity\Actions\ResetCustomerPassword;
use Modules\Identity\Actions\UpdateCustomerProfile;
use Modules\Identity\Data\CustomerProfileData;
use Modules\Identity\Http\Resources\CustomerResource;
use Modules\Identity\Models\Customer;

/**
 * The signed-in customer's own account, and password recovery.
 */
final class AccountController
{
    public function profile(Request $request): CustomerResource
    {
        return CustomerResource::make(self::customer($request));
    }

    public function updateProfile(Request $request, UpdateCustomerProfile $update): CustomerResource
    {
        return CustomerResource::make($update->handle(self::customer($request), CustomerProfileData::validateAndCreate($request)));
    }

    public function changePassword(Request $request, ChangeCustomerPassword $change): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $customer = self::customer($request);
        $token = $customer->currentAccessToken();

        $change->handle($customer, (string) $data['current_password'], (string) $data['password'], $token instanceof PersonalAccessToken ? (int) $token->getKey() : null);

        return response()->noContent();
    }

    public function forgotPassword(Request $request, RequestCustomerPasswordReset $reset): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        $reset->handle((string) $data['email']);

        // The same answer whether or not the account exists.
        return response()->json(['data' => ['message' => __('If an account exists for that email, a reset link is on its way.')]], 202);
    }

    public function resetPassword(Request $request, ResetCustomerPassword $reset): Response
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $reset->handle((string) $data['email'], (string) $data['token'], (string) $data['password']);

        return response()->noContent();
    }

    private static function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer;
    }
}
