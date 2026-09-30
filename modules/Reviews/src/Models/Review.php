<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $product_ulid
 * @property int|null $customer_id
 * @property string|null $order_ulid
 * @property string $author_name
 * @property int $rating
 * @property string|null $title
 * @property string $body
 * @property string $status
 * @property Carbon|null $moderated_at
 * @property Carbon $created_at
 */
final class Review extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'moderated_at' => 'datetime'];
    }

    /**
     * @return array{id: string, rating: int, title: string|null, body: string, author_name: string, verified_purchase: bool, created_at: string}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->ulid,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'author_name' => $this->author_name,
            'verified_purchase' => $this->order_ulid !== null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * What the author sees after submitting: the public shape plus the moderation status.
     *
     * @return array{id: string, rating: int, title: string|null, body: string, author_name: string, verified_purchase: bool, created_at: string, status: string}
     */
    public function toSubmissionApi(): array
    {
        return [
            'id' => $this->ulid,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'author_name' => $this->author_name,
            'verified_purchase' => $this->order_ulid !== null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'status' => $this->status,
        ];
    }

    /**
     * @param  iterable<self>  $reviews
     * @return list<array{id: string, rating: int, title: string|null, body: string, author_name: string, verified_purchase: bool, created_at: string}>
     *
     * @scramble-return list<array{id: string, rating: int, title: string|null, body: string, author_name: string, verified_purchase: bool, created_at: string}>
     */
    public static function listToApi(iterable $reviews): array
    {
        $list = [];

        foreach ($reviews as $review) {
            $list[] = $review->toApi();
        }

        return $list;
    }
}
