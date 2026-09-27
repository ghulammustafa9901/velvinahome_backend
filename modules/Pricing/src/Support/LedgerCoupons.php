<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use Illuminate\Support\Facades\DB;
use Modules\Pricing\Contracts\Coupons;
use Modules\Pricing\Exceptions\CouponUnavailable;
use Modules\Pricing\Models\Coupon;
use Modules\Support\ValueObjects\Money;

final class LedgerCoupons implements Coupons
{
    public function redeem(string $code, string $orderUlid, ?int $customerId, ?string $email, Money $discount): void
    {
        $coupon = Coupon::query()->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])->lockForUpdate()->first()
            ?? throw CouponUnavailable::because($code, 'it does not exist');

        // Re-check under the lock. Spend rules were validated by the quote moments ago in the
        // same transaction; only the window and usage limits can have changed since.
        $problem = CouponRules::usageProblem($coupon, $customerId, $email);

        if ($problem !== null) {
            throw CouponUnavailable::because($code, $problem);
        }

        $coupon->increment('used_count');

        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $coupon->id,
            'order_ulid' => $orderUlid,
            'customer_id' => $customerId,
            'email' => $email === null ? null : mb_strtolower($email),
            'discount_amount' => $discount->amount,
            'discount_currency' => $discount->currency->value,
            'created_at' => now(),
        ]);
    }

    public function void(string $orderUlid): void
    {
        $redemptions = DB::table('coupon_redemptions')->where('order_ulid', $orderUlid)->whereNull('voided_at')->get();

        foreach ($redemptions as $redemption) {
            DB::table('coupon_redemptions')->where('id', $redemption->id)->update(['voided_at' => now()]);
            Coupon::query()->whereKey($redemption->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }
    }
}
