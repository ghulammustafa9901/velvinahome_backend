<?php

declare(strict_types=1);

namespace Modules\Orders\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Orders\Actions\SubmitPaymentProof;
use Modules\Orders\Models\Order;
use Modules\Orders\Support\OrderPresenter;

/**
 * Order status for the customer: a guest proves access with the `X-Order-Token` returned at
 * placement; a signed-in customer sees their own orders. Anything else is 404 (no IDOR).
 */
final class OrderController
{
    public const TOKEN_HEADER = 'X-Order-Token';

    public function __construct(private readonly OrderPresenter $presenter) {}

    public function show(Request $request, string $ulid): JsonResponse
    {
        return $this->respond($this->accessible($request, $ulid));
    }

    public function submitPaymentProof(Request $request, string $ulid, SubmitPaymentProof $submit): JsonResponse
    {
        $data = $request->validate([
            'receipt' => ['required', 'file', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var UploadedFile $receipt */
        $receipt = $data['receipt'];

        return $this->respond($submit->handle($this->accessible($request, $ulid), $receipt, $data['reference'] ?? null));
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        abort_if($customer === null, 401);

        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));

        $page = Order::query()
            ->where('customer_id', (int) $customer->getAuthIdentifier())
            ->withSum('items as item_count', 'quantity')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $this->presenter->summaries($page->items()),
            'meta' => ['per_page' => $perPage, 'has_more' => $page->hasMorePages()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ])->header('Cache-Control', 'no-store');
    }

    private function accessible(Request $request, string $ulid): Order
    {
        $order = Order::query()->where('ulid', $ulid)->first();
        $customer = $request->user('customer');
        $token = $request->header(self::TOKEN_HEADER);

        $allowed = $order !== null && (
            ($customer !== null && $order->customer_id === (int) $customer->getAuthIdentifier())
            || (is_string($token) && $token !== '' && hash_equals($order->access_token_hash, hash('sha256', $token)))
        );

        abort_unless($allowed, 404);

        return $order;
    }

    private function respond(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($order)])->header('Cache-Control', 'no-store');
    }
}
