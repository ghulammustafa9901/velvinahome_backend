<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Customers\Actions\AddToWishlist;
use Modules\Customers\Actions\RemoveFromWishlist;
use Modules\Customers\Models\WishlistItem;

/**
 * The signed-in customer's wishlist. Product cards come from
 * `GET /products?filter[ids]=…` so prices and stock are always live.
 */
final class WishlistController
{
    public function index(Request $request): JsonResponse
    {
        $items = WishlistItem::query()->where('customer_id', self::customerId($request))->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn (WishlistItem $item): array => $item->toApi())->values()->all()]);
    }

    public function store(Request $request, AddToWishlist $add): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'size:26'],
            'variant_id' => ['nullable', 'string', 'size:26'],
        ]);

        $item = $add->handle(self::customerId($request), (string) $data['product_id'], $data['variant_id'] ?? null);

        // Already on the list: 200 with the existing item.
        if (! $item->wasRecentlyCreated) {
            return response()->json(['data' => $item->toApi()], 200);
        }

        return response()->json(['data' => $item->toApi()], 201);
    }

    public function destroy(Request $request, string $ulid, RemoveFromWishlist $remove): Response
    {
        $remove->handle(self::customerId($request), $ulid);

        return response()->noContent();
    }

    private static function customerId(Request $request): int
    {
        return (int) $request->user('customer')?->getAuthIdentifier();
    }
}
