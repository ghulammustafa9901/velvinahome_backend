<?php

declare(strict_types=1);

namespace Modules\Reviews\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Catalog\Contracts\ProductDirectory;
use Modules\Orders\Contracts\OrderBook;
use Modules\Reviews\Models\Review;
use Modules\Support\Contracts\Action;

/**
 * A customer reviews a product they received. One review per product per customer (a second
 * submission edits the first and sends it back to moderation). Nothing is public until staff
 * approve it.
 */
final class SubmitReview implements Action
{
    public function __construct(
        private readonly ProductDirectory $products,
        private readonly OrderBook $orders,
    ) {}

    public function handle(int $customerId, string $customerName, string $productUlid, int $rating, ?string $title, string $body): Review
    {
        if (! $this->products->isLive($productUlid)) {
            throw ValidationException::withMessages(['product_id' => __('This product cannot be reviewed.')]);
        }

        $order = $this->orders->deliveredOrderWithProduct($customerId, $productUlid);

        if ($order === null) {
            throw ValidationException::withMessages(['product_id' => __('You can review products from your delivered orders.')]);
        }

        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => __('Choose between 1 and 5 stars.')]);
        }

        $review = Review::query()->firstOrNew(['product_ulid' => $productUlid, 'customer_id' => $customerId]);
        $review->forceFill([
            'order_ulid' => $order,
            'author_name' => mb_substr(trim($customerName) !== '' ? trim($customerName) : __('Customer'), 0, 100),
            'rating' => $rating,
            'title' => $title === null || trim($title) === '' ? null : mb_substr(trim($title), 0, 150),
            'body' => mb_substr(trim($body), 0, 5000),
            'status' => 'pending',
            'moderated_at' => null,
            'moderator_ulid' => null,
        ])->save();

        return $review;
    }
}
