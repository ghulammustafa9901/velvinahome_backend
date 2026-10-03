<?php

declare(strict_types=1);

namespace Modules\Notifications\Handlers;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Contracts\Carts;
use Modules\Integrations\Contracts\OutboxHandler;
use Modules\Notifications\Mail\CartReminderMail;
use Modules\Notifications\Models\NotificationTemplate;
use Modules\Notifications\Support\EmailPreferences;
use Modules\Settings\Contracts\StoreSettings;

/**
 * `cart.abandoned`: the one reminder for a cart. Re-checks everything at send time — the cart
 * may have been bought, emptied or recovered since, or the address unsubscribed — and links
 * only to the store URL setting (never request input).
 */
final class CartAbandonedEmail implements OutboxHandler
{
    public function __construct(
        private readonly Carts $carts,
        private readonly StoreSettings $settings,
        private readonly Mailer $mailer,
    ) {}

    public function handle(string $messageUlid, array $payload): void
    {
        if (DB::table('notification_deliveries')->where('outbox_message_ulid', $messageUlid)->exists()) {
            return;
        }

        $template = NotificationTemplate::query()->where('key', 'cart.abandoned')->first();
        $cartUlid = $payload['cart_ulid'] ?? null;
        $reminder = is_string($cartUlid) ? $this->carts->reminder($cartUlid) : null;
        $storefront = $this->settings->storefrontUrl();

        if ($template === null || ! $template->is_enabled || $reminder === null || $storefront === null || EmailPreferences::suppressed($reminder->email)) {
            return;
        }

        $storeName = $this->settings->string('mail.from_name') ?: $this->settings->string('store.name', 'Store');
        $values = ['store_name' => $storeName];

        $this->mailer->to($reminder->email)->send(new CartReminderMail(
            subjectLine: NotificationTemplate::render($template->subject, $values),
            intro: NotificationTemplate::render($template->intro, $values),
            lines: $reminder->lines,
            total: $reminder->total,
            recoverUrl: $storefront.'/cart/recover?'.http_build_query(['cart' => $reminder->cartUlid, 'token' => $reminder->recoveryToken]),
            unsubscribeUrl: $storefront.'/unsubscribe?'.http_build_query(['email' => $reminder->email, 'signature' => EmailPreferences::signature($reminder->email)]),
            storeName: $storeName,
            fromAddress: $this->settings->string('mail.from_address') ?: null,
        ));

        DB::table('notification_deliveries')->insert([
            'outbox_message_ulid' => $messageUlid,
            'template_key' => $template->key,
            'cart_ulid' => $reminder->cartUlid,
            'channel' => 'mail',
            'sent_at' => now(),
        ]);
    }
}
