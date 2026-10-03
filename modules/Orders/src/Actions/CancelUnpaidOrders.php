<?php

declare(strict_types=1);

namespace Modules\Orders\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Orders\Models\Order;
use Modules\Orders\States\PendingPayment;
use Modules\Orders\Support\OrderCanceller;
use Modules\Orders\Support\OrderLock;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Contracts\Action;

/**
 * Scheduled: a transfer order that is still unpaid, with no receipt uploaded, after the
 * store's payment window is cancelled so its reserved stock goes back on sale. Orders with a
 * receipt awaiting verification are left for staff.
 */
final class CancelUnpaidOrders implements Action
{
    public const DEFAULT_WINDOW_HOURS = 48;

    public function __construct(
        private readonly OrderCanceller $canceller,
        private readonly StoreSettings $settings,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(): int
    {
        $hours = (int) $this->settings->get('orders.payment_window_hours', self::DEFAULT_WINDOW_HOURS);

        $ulids = Order::query()
            ->where('status', PendingPayment::getMorphClass())
            ->where('payment_status', 'unpaid')
            ->where('placed_at', '<', now()->subHours(max(1, $hours)))
            ->pluck('ulid');

        $cancelled = 0;

        foreach ($ulids as $ulid) {
            $cancelled += $this->db->connection()->transaction(function () use ($ulid, $hours): int {
                $order = OrderLock::take($ulid);

                // Re-checked under the lock: a receipt may have arrived since the query.
                if (! $order->status instanceof PendingPayment || $order->payment_status !== 'unpaid') {
                    return 0;
                }

                $this->canceller->cancel($order, "Not paid within {$hours} hours", null);

                return 1;
            });
        }

        return $cancelled;
    }
}
