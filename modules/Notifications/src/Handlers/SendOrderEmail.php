<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Notifications\Mail\OrderMail;
use Modules\Notifications\Models\NotificationTemplate;
use Modules\Orders\Contracts\OrderBook;
use Modules\Settings\Contracts\StoreSettings;
use Modules\Support\Enums\Currency;
use Modules\Support\ValueObjects\Money;

/**
 * Emails the customer for one `order.*` outbox event. One subclass per event type, because
 * the outbox registry maps event types to handler classes.
 *
 * Idempotent per outbox message: a message redelivered after a crash is not sent twice.
 */
abstract class SendOrderEmail implements OutboxHandler
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly StoreSettings $settings,
        private readonly Mailer $mailer,
    ) {}

    abstract public function templateKey(): string;

    public function handle(string $messageUlid, array $payload): void
    {
        if (DB::table('notification_deliveries')->where('outbox_message_ulid', $messageUlid)->exists()) {
            return;
        }

        $template = NotificationTemplate::query()->where('key', $this->templateKey())->first();
        $orderUlid = $payload['order_ulid'] ?? null;
        $order = is_string($orderUlid) ? $this->orders->describe($orderUlid) : null;

        if ($template === null || ! $template->is_enabled || $order === null) {
            return;
        }

        $storeName = $this->settings->string('mail.from_name') ?: $this->settings->string('store.name', 'Store');
        $values = $this->placeholders($order, $payload, $storeName);

        $this->mailer->to((string) $order['email'])->send(new OrderMail(
            subjectLine: NotificationTemplate::render($template->subject, $values),
            intro: NotificationTemplate::render($template->intro, $values),
            order: $order,
            storeName: $storeName,
            fromAddress: $this->settings->string('mail.from_address') ?: null,
        ));

        DB::table('notification_deliveries')->insert([
            'outbox_message_ulid' => $messageUlid,
            'template_key' => $template->key,
            'order_ulid' => $orderUlid,
            'channel' => 'mail',
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function placeholders(array $order, array $payload, string $storeName): array
    {
        /** @var list<array{id: string, courier: string|null, tracking_number: string|null, tracking_url?: string|null}> $shipments */
        $shipments = $order['shipments'];
        $shipment = collect($shipments)->firstWhere('id', $payload['shipment_ulid'] ?? null) ?? end($shipments) ?: null;

        /** @var Money $total */
        $total = $order['totals']['total'];

        $refund = isset($payload['amount'], $payload['currency']) && is_int($payload['amount']) && is_string($payload['currency'])
            ? Money::of($payload['amount'], Currency::from($payload['currency']))->format()
            : null;

        return [
            'store_name' => $storeName,
            'customer_name' => $order['shipping_address']['first_name'] ?? null,
            'order_number' => (string) $order['number'],
            'order_total' => $total->format(),
            'courier' => $shipment['courier'] ?? null,
            'tracking_number' => $shipment['tracking_number'] ?? null,
            'tracking_url' => $shipment['tracking_url'] ?? null,
            'refund_amount' => $refund,
        ];
    }
}
