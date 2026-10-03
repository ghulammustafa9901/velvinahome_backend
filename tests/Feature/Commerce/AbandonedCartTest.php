<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cart\Actions\CreateCart;
use Modules\Cart\Actions\SendCartReminders;
use Modules\Cart\Actions\SetCartItem;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Actions\RememberCheckoutEmail;
use Modules\Integrations\Actions\PublishPendingOutboxMessages;
use Modules\Integrations\Jobs\PublishOutboxMessages;
use Modules\Notifications\Mail\CartReminderMail;
use Modules\Notifications\Support\EmailPreferences;
use Tests\Support\TestCommerce;
use Tests\Support\TestSettings;

/*
| Abandoned-cart reminders (spec §13 phase 6): one reminder per guest cart with a checkout
| email, only with the feature on, never after the cart was bought, never to an address
| that unsubscribed; the link reopens the cart in the clicking browser.
*/

beforeEach(function (): void {
    Mail::fake();
    $this->api = 'http://localhost/api/v1';
});

afterEach(function (): void {
    TestSettings::feature('abandoned_cart', false);
});

function remindersOn(object $test): void
{
    TestSettings::feature('abandoned_cart', true);
}

/**
 * A guest cart with an item and a checkout email, last touched $minutesAgo minutes ago.
 *
 * @return array{cart: Cart, token: string, email: string}
 */
function abandonedCart(int $minutesAgo = 180): array
{
    $created = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($created['cart'], TestCommerce::stockedVariant(90_000, 5)->ulid, 1);
    $email = 'ab-'.Str::lower(Str::random(8)).'@example.test';
    app(RememberCheckoutEmail::class)->handle($created['cart']->ulid, null, $email);
    Cart::query()->whereKey($created['cart']->id)->toBase()->update(['updated_at' => Carbon::now()->subMinutes($minutesAgo)]);

    return ['cart' => $created['cart'], 'token' => (string) $created['token'], 'email' => $email];
}

it('sends nothing while the feature is off', function (): void {
    $cart = abandonedCart();

    expect(app(SendCartReminders::class)->handle())->toBe(0)
        ->and($cart['cart']->refresh()->reminded_at)->toBeNull();
});

it('reminds once, with a link that reopens the cart in the clicking browser', function (): void {
    remindersOn($this);
    $cart = abandonedCart();
    $fresh = abandonedCart(minutesAgo: 10);

    app(SendCartReminders::class)->handle();
    app(PublishPendingOutboxMessages::class)->handle();

    expect($cart['cart']->refresh()->reminded_at)->not->toBeNull()
        ->and($fresh['cart']->refresh()->reminded_at)->toBeNull();

    $mail = null;
    Mail::assertSent(CartReminderMail::class, function (CartReminderMail $sent) use ($cart, &$mail): bool {
        if (! $sent->hasTo($cart['email'])) {
            return false;
        }

        $mail = $sent;

        return true;
    });

    expect($mail->recoverUrl)->toContain('://store.test/cart/recover?cart='.$cart['cart']->ulid.'&token=')
        ->and($mail->lines)->toHaveCount(1);

    // A second run does not chase the same cart again.
    app(SendCartReminders::class)->handle();
    expect(Cart::query()->whereKey($cart['cart']->id)->value('reminded_at'))->toEqual($cart['cart']->reminded_at);

    // The link's secret opens the cart with a new token; the old browser's token stops working.
    parse_str((string) parse_url($mail->recoverUrl, PHP_URL_QUERY), $query);

    $recovered = $this->postJson($this->api.'/carts/'.$cart['cart']->ulid.'/recover', ['token' => $query['token']])->assertOk();
    $newToken = (string) $recovered->json('data.token');

    expect($newToken)->not->toBe('')->not->toBe($cart['token'])
        ->and($recovered->json('data.lines'))->toHaveCount(1);

    $this->getJson($this->api.'/carts/'.$cart['cart']->ulid, ['X-Cart-Token' => $cart['token']])->assertNotFound();
    $this->getJson($this->api.'/carts/'.$cart['cart']->ulid, ['X-Cart-Token' => $newToken])->assertOk();
    $this->postJson($this->api.'/carts/'.$cart['cart']->ulid.'/recover', ['token' => str_repeat('0', 64)])->assertNotFound();
});

it('does not email a cart that was bought or an address that unsubscribed', function (): void {
    remindersOn($this);
    $bought = abandonedCart();
    $unsubscribed = abandonedCart();

    // The outbox publishes later (as it does on a real queue), not inside this call.
    Bus::fake([PublishOutboxMessages::class]);
    app(SendCartReminders::class)->handle();

    // Between the reminder being queued and sent: one cart is bought, one address opts out.
    Cart::query()->whereKey($bought['cart']->id)->toBase()->update(['status' => 'converted']);
    $this->postJson($this->api.'/email-preferences/unsubscribe', ['email' => $unsubscribed['email'], 'signature' => str_repeat('a', 64)])->assertUnprocessable();
    $this->postJson($this->api.'/email-preferences/unsubscribe', ['email' => $unsubscribed['email'], 'signature' => EmailPreferences::signature($unsubscribed['email'])])->assertNoContent();
    app(PublishPendingOutboxMessages::class)->handle();

    Mail::assertNotSent(CartReminderMail::class, fn (CartReminderMail $mail): bool => $mail->hasTo($bought['email']) || $mail->hasTo($unsubscribed['email']));
});

it('keeps the email a guest types at checkout on the cart', function (): void {
    $created = app(CreateCart::class)->handle(null);
    app(SetCartItem::class)->handle($created['cart'], TestCommerce::stockedVariant(90_000, 5)->ulid, 1);

    $this->postJson($this->api.'/checkout/quote', ['cart_id' => $created['cart']->ulid, 'email' => 'Typed@Example.test'], ['X-Cart-Token' => $created['token']])->assertOk();
    expect($created['cart']->refresh()->email)->toBe('typed@example.test');
});
