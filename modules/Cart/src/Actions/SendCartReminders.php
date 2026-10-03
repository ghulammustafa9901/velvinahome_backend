<?php

declare(strict_types=1);

namespace Modules\Cart\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Cart\Models\Cart;
use Modules\Integrations\Contracts\Outbox;
use Modules\Settings\Contracts\FeatureFlags;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Contracts\Action;

/**
 * One reminder per abandoned guest cart (feature `abandoned_cart`): a cart with items and a
 * checkout email, untouched for the store's delay (default two hours) but not older than three
 * days. The email itself goes through the outbox (`cart.abandoned`), which re-checks the cart
 * right before sending, so a cart bought in between is never chased.
 */
final class SendCartReminders implements Action
{
    public const BATCH = 200;

    public function __construct(
        private readonly FeatureFlags $features,
        private readonly StoreSettings $settings,
        private readonly Outbox $outbox,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return int reminders queued
     */
    public function handle(): int
    {
        if (! $this->features->enabled('abandoned_cart')) {
            return 0;
        }

        $delay = min(4320, max(30, (int) $this->settings->string('cart.reminder_after_minutes', '120')));
        $now = Carbon::now();

        $carts = Cart::query()
            ->where('status', 'active')
            ->whereNull('customer_id')
            ->whereNotNull('email')
            ->whereNull('reminded_at')
            ->where('updated_at', '<=', $now->copy()->subMinutes($delay))
            ->where('updated_at', '>=', $now->copy()->subDays(3))
            ->where('expires_at', '>', $now)
            ->whereHas('items')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('ulid');

        foreach ($carts as $ulid) {
            $this->db->connection()->transaction(function () use ($ulid, $now): void {
                // Without touching updated_at: a reminder is not the shopper's activity.
                $claimed = Cart::query()->where('ulid', $ulid)->whereNull('reminded_at')->toBase()
                    ->update(['reminded_at' => $now->copy()->startOfSecond()]);

                if ($claimed === 1) {
                    $this->outbox->record('cart.abandoned', ['cart_ulid' => $ulid]);
                }
            });
        }

        return $carts->count();
    }
}
