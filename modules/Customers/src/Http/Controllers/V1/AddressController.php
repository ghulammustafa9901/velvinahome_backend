<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Customers\Actions\DeleteAddress;
use Modules\Customers\Actions\SaveAddress;
use Modules\Customers\Data\AddressData;
use Modules\Customers\Models\CustomerAddress;

/**
 * The signed-in customer's address book. Every query is scoped to the token's customer.
 */
final class AddressController
{
    public function index(Request $request): JsonResponse
    {
        $addresses = CustomerAddress::query()->where('customer_id', self::customerId($request))->orderByDesc('is_default_shipping')->orderBy('id')->get();

        return response()->json(['data' => $addresses->map(fn (CustomerAddress $address): array => $address->toApi())->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, SaveAddress $save): JsonResponse
    {
        $address = $save->handle(
            self::customerId($request),
            null,
            AddressData::validateAndCreate($request),
            $request->string('label')->toString() ?: null,
            $request->boolean('is_default_shipping'),
            $request->boolean('is_default_billing'),
        );

        return response()->json(['data' => $address->toApi()], 201);
    }

    public function update(Request $request, string $ulid, SaveAddress $save): JsonResponse
    {
        $address = $save->handle(
            self::customerId($request),
            $ulid,
            AddressData::validateAndCreate($request),
            $request->string('label')->toString() ?: null,
            $request->boolean('is_default_shipping'),
            $request->boolean('is_default_billing'),
        );

        return response()->json(['data' => $address->toApi()]);
    }

    public function destroy(Request $request, string $ulid, DeleteAddress $delete): Response
    {
        $delete->handle(self::customerId($request), $ulid);

        return response()->noContent();
    }

    private static function customerId(Request $request): int
    {
        return (int) $request->user('customer')?->getAuthIdentifier();
    }
}
