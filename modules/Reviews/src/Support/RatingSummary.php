<?php

declare(strict_types=1);

namespace Modules\Reviews\Support;

use Illuminate\Support\Facades\DB;

/**
 * Star-rating summary of a product's approved reviews.
 */
final class RatingSummary
{
    /**
     * @return array{count: int, average: float|null, distribution: list<array{rating: int, count: int}>}
     *
     * @scramble-return array{count: int, average: float|null, distribution: list<array{rating: int, count: int}>}
     */
    public function for(string $productUlid): array
    {
        $rows = DB::table('reviews')
            ->where('product_ulid', $productUlid)
            ->where('status', 'approved')
            ->groupBy('rating')
            ->select('rating', DB::raw('count(*) as reviews'))
            ->pluck('reviews', 'rating');

        // Five stars first, as displayed.
        $distribution = array_map(
            fn (int $rating): array => ['rating' => $rating, 'count' => (int) ($rows[$rating] ?? 0)],
            [5, 4, 3, 2, 1],
        );
        $count = 0;
        $stars = 0;

        foreach ($distribution as $row) {
            $count += $row['count'];
            $stars += $row['rating'] * $row['count'];
        }

        return [
            'count' => $count,
            // One decimal, as displayed ("4.3").
            'average' => $count === 0 ? null : round($stars / $count, 1),
            'distribution' => $distribution,
        ];
    }
}
