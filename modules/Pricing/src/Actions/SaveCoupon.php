<?php

declare(strict_types=1);

namespace Modules\Pricing\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Pricing\Data\CouponData;
use Modules\Pricing\Models\Coupon;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class SaveCoupon implements Action
{
    use ChecksPermission;

    public function handle(?Coupon $coupon, CouponData $data, ?Authorizable $actor = null): Coupon
    {
        $this->authorizePermission($actor, 'promotions.manage');

        $coupon ??= new Coupon;
        $coupon->fill([
            'code' => strtoupper(trim($data->code)),
            'discount_type' => $data->discountType,
            'value' => $data->value,
            'minimum_spend_amount' => $data->minimumSpendAmount,
            'usage_limit' => $data->usageLimit,
            'usage_limit_per_customer' => $data->usageLimitPerCustomer,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
            'conditions' => array_filter([
                'product_ulids' => $data->productUlids,
                'category_ulids' => $data->categoryUlids,
            ]),
            'is_active' => $data->isActive,
        ])->save();

        return $coupon;
    }
}
