<?php

declare(strict_types=1);

namespace Modules\Pricing\Filament\Resources\Coupons;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Pricing\Actions\SaveCoupon;
use Modules\Pricing\Models\Coupon;

final class ListCouponsPage extends ListRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(CouponResource::fields())
                ->using(fn (array $data): Coupon => app(SaveCoupon::class)->handle(null, CouponResource::toData($data), CouponResource::actor())),
        ];
    }
}
