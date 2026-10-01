<?php

declare(strict_types=1);

namespace Modules\Notifications\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notifications\Models\NotificationTemplate;

/**
 * Default wording; brands edit it in the admin. Idempotent: never overwrites edits.
 */
final class DefaultNotificationTemplatesSeeder extends Seeder
{
    public const DEFAULTS = [
        'order.placed' => ['Order {{ order_number }} received', "Hi {{ customer_name }}, thank you for your order! We've received order {{ order_number }} and will be in touch shortly.", true],
        'order.confirmed' => ['Order {{ order_number }} confirmed', 'Hi {{ customer_name }}, your order {{ order_number }} is confirmed and is being prepared.', true],
        'order.shipped' => ['Order {{ order_number }} is on its way', 'Hi {{ customer_name }}, your order {{ order_number }} has shipped with {{ courier }}. Tracking number: {{ tracking_number }}.', true],
        'order.delivered' => ['Order {{ order_number }} delivered', 'Hi {{ customer_name }}, your order {{ order_number }} has been delivered. We hope you love it!', true],
        'order.cancelled' => ['Order {{ order_number }} cancelled', 'Hi {{ customer_name }}, your order {{ order_number }} has been cancelled.', true],
        'order.refunded' => ['Refund for order {{ order_number }}', 'Hi {{ customer_name }}, we have refunded {{ refund_amount }} for your order {{ order_number }}.', true],
        'order.returned' => ['Return received for order {{ order_number }}', 'Hi {{ customer_name }}, we have received the return for your order {{ order_number }}.', false],
        'cart.abandoned' => ['You left something in your cart', 'Hi, your cart at {{ store_name }} is saved. Pick up where you left off — items sell out.', true],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => [$subject, $intro, $enabled]) {
            NotificationTemplate::query()->firstOrCreate(['key' => $key], ['subject' => $subject, 'intro' => $intro, 'is_enabled' => $enabled]);
        }
    }
}
