<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use Illuminate\Support\Facades\DB;
use Modules\Pricing\Models\Coupon;
use Modules\Support\ValueObjects\Money;

/**
 * Whether a coupon may be used now by this customer on this spend. One implementation,
 * used both when quoting and again under lock when an order is placed.
 */
final class CouponRules
{
    public static function find(string $code): ?Coupon
    {
        return Coupon::query()->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])->first();
    }

    /**
     * @return string|null why the coupon cannot be used, or null when it can
     */
    public static function problem(Coupon $coupon, Money $eligibleSubtotal, ?int $customerId, ?string $email): ?string
    {
        if ($coupon->minimum_spend_amount !== null && $eligibleSubtotal->amount < $coupon->minimum_spend_amount) {
            return 'Minimum spend of '.Money::of($coupon->minimum_spend_amount, $eligibleSubtotal->currency)->format().' not reached.';
        }

        if ($eligibleSubtotal->isZero()) {
            return 'No item in the cart qualifies for this coupon.';
        }

        return self::usageProblem($coupon, $customerId, $email);
    }

    /**
     * The rules that can change between quote and order: validity window and usage limits.
     */
    public static function usageProblem(Coupon $coupon, ?int $customerId, ?string $email): ?string
    {
        if (! $coupon->is_active) {
            return 'This coupon is not active.';
        }

        if (($coupon->starts_at !== null && $coupon->starts_at->isFuture()) || ($coupon->ends_at !== null && $coupon->ends_at->isPast())) {
            return 'This coupon is not valid at this time.';
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return 'This coupon has been fully redeemed.';
        }

        if ($coupon->usage_limit_per_customer !== null && ($customerId !== null || $email !== null)) {
            $used = DB::table('coupon_redemptions')
                ->where('coupon_id', $coupon->id)
                ->whereNull('voided_at')
                ->where(fn ($query) => $query
                    ->when($customerId !== null, fn ($q) => $q->orWhere('customer_id', $customerId))
                    ->when($email !== null, fn ($q) => $q->orWhereRaw('lower(email) = ?', [mb_strtolower((string) $email)])))
                ->count();

            if ($used >= $coupon->usage_limit_per_customer) {
                return 'You have already used this coupon.';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $categoryUlids  the line's categories, including ancestors
     */
    public static function appliesTo(Coupon $coupon, string $productUlid, array $categoryUlids): bool
    {
        $products = $coupon->conditions['product_ulids'] ?? [];
        $categories = $coupon->conditions['category_ulids'] ?? [];

        if ($products === [] && $categories === []) {
            return true;
        }

        return in_array($productUlid, $products, true) || array_intersect($categories, $categoryUlids) !== [];
    }
}
