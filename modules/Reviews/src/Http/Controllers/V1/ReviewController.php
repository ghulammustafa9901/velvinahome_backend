<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Controllers\V1;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reviews\Actions\SubmitReview;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Support\RatingSummary;

final class ReviewController
{
    /**
     * Approved reviews of a product, newest first, with the rating summary.
     */
    #[QueryParameter('per_page', description: '1–50, default 10.', type: 'integer')]
    #[QueryParameter('cursor', type: 'string')]
    public function index(Request $request, string $product, RatingSummary $summary): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->integer('per_page', 10)));

        $page = Review::query()->where('product_ulid', $product)->where('status', 'approved')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($perPage)->withQueryString();

        return response()->json([
            'data' => Review::listToApi($page->items()),
            'meta' => [
                'per_page' => $perPage,
                'has_more' => $page->hasMorePages(),
                'summary' => $summary->for($product),
            ],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ])->header('Cache-Control', 'public, max-age=0, s-maxage=300, stale-while-revalidate=600');
    }

    public function store(Request $request, string $product, SubmitReview $submit): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $customer = $request->user('customer');
        abort_if($customer === null, 401);

        // Public display name: first name and last initial.
        $name = trim($customer->getAttribute('first_name').' '.mb_substr((string) $customer->getAttribute('last_name'), 0, 1));

        $review = $submit->handle((int) $customer->getAuthIdentifier(), $name, $product, (int) $data['rating'], $data['title'] ?? null, (string) $data['body']);

        return response()->json(['data' => $review->toSubmissionApi()], 201);
    }
}
