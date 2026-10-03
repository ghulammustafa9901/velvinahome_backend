<?php

declare(strict_types=1);

namespace Modules\Payments\Seeders;

use Illuminate\Database\Seeder;
use Modules\Payments\Models\PaymentMethod;

/**
 * Cash on Delivery on by default (the dominant method, spec §1.3); manual transfer present but
 * off until staff enter the store's account details.
 */
final class DefaultPaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        PaymentMethod::query()->firstOrCreate(['code' => 'cod'], [
            'label' => 'Cash on Delivery',
            'description' => 'Pay in cash when your order arrives.',
            'is_enabled' => true,
            'position' => 1,
        ]);

        PaymentMethod::query()->firstOrCreate(['code' => 'manual_transfer'], [
            'label' => 'Bank / wallet transfer',
            'description' => 'Transfer the total, then upload your receipt. We confirm your order once the money arrives.',
            'is_enabled' => false,
            'config' => ['instructions' => null],
            'position' => 2,
        ]);
    }
}
