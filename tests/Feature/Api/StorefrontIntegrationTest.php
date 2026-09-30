<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Identity\Models\LoginAudit;
use Modules\Integrations\Models\OutboxMessage;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| The storefront BFF integration (ADR-0002/0003, spec §6.13): signed render calls are not
| rate-limited per server IP; signed customer calls carry the customer's IP; catalog changes
| purge the brand's ISR tags through a signed revalidation call.
*/

beforeEach(function (): void {
    $this->secret = str_repeat('s', 40);
    config(['platform.service_auth.secret' => $this->secret]);
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1';
});

it('does not rate-limit the storefront rendering pages, but still limits unsigned callers', function (): void {
    $render = ['X-Storefront-Render' => hash_hmac('sha256', 'storefront-render:v1', $this->secret)];

    // Exhaust the per-IP catalog budget (120/min) as an ordinary caller…
    foreach (range(1, 120) as $request) {
        $this->getJson($this->api.'/categories')->assertOk();
    }

    $this->getJson($this->api.'/categories')->assertStatus(429);
    $this->withHeader('X-Storefront-Render', 'forged')->getJson($this->api.'/categories')->assertStatus(429);

    // …while the storefront's signed page rendering from the same IP is still served.
    $this->flushHeaders()->withHeaders($render)->getJson($this->api.'/categories')->assertOk();
});

it('attributes signed customer calls to the customer IP, and ignores bad signatures', function (): void {
    $email = 'ip-'.Str::lower(Str::random(8)).'@example.test';
    $sign = function (string $ip, ?int $at = null): array {
        $timestamp = (string) ($at ?? time());

        return ['X-Storefront-Client-Ip' => $ip, 'X-Storefront-Signature' => 'v1,'.$timestamp.','.hash_hmac('sha256', $timestamp."\n".$ip, $this->secret)];
    };

    $this->withHeaders($sign('203.0.113.9'))->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'nope-nope'])->assertUnprocessable();
    $this->flushHeaders()->withHeaders(['X-Storefront-Signature' => 'v1,'.time().',forged', 'X-Storefront-Client-Ip' => '198.51.100.7'])
        ->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'nope-nope'])->assertUnprocessable();
    $this->flushHeaders()->withHeaders($sign('192.0.2.44', time() - 3600))->postJson($this->api.'/auth/login', ['email' => $email, 'password' => 'nope-nope'])->assertUnprocessable();

    $ips = TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => LoginAudit::query()->where('email_attempted', $email)->orderBy('id')->pluck('ip')->all());

    expect($ips)->toBe(['203.0.113.9', '127.0.0.1', '127.0.0.1']);
});

it('keeps the brand chosen by the proxy forwarded host when the customer IP is applied', function (): void {
    $timestamp = (string) time();
    $ip = '203.0.113.10';

    // As in local development: the storefront calls the API directly and names the brand with
    // X-Forwarded-Host from a loopback (trusted) address.
    $this->withHeaders([
        'X-Forwarded-Host' => TestPlatform::apiHost(TestPlatform::BRAND_C),
        'X-Storefront-Client-Ip' => $ip,
        'X-Storefront-Signature' => 'v1,'.$timestamp.','.hash_hmac('sha256', $timestamp."\n".$ip, $this->secret),
    ])->postJson('http://127.0.0.1/api/v1/carts')->assertCreated();
});

it('purges the brand storefront tags after a catalog change, signed', function (): void {
    config([
        'platform.storefront.revalidate_secret' => str_repeat('r', 40),
        'platform.storefront.revalidate_url' => 'http://storefront.internal/api/revalidate',
    ]);
    Http::fake(['storefront.internal/*' => Http::response(['ok' => true])]);

    $brand = TestPlatform::brand(TestPlatform::BRAND_C);
    // Only this test's messages: the shared fixture brand may hold others from earlier tests.
    $before = (int) $brand->run(fn () => OutboxMessage::query()->max('id'));
    $slug = $brand->run(function (): string {
        $product = TestCatalog::simpleProduct(publish: true);
        app(UpdateProductDetails::class)->handle($product, new ProductDetailsData(title: $product->title, slug: $product->slug.'-renamed'));

        return $product->refresh()->slug;
    });

    Http::assertSent(function (HttpRequest $request) use ($slug): bool {
        $body = $request->body();
        $expected = hash_hmac('sha256', $request->header('X-Revalidate-Timestamp')[0]."\n".$body, str_repeat('r', 40));
        $payload = json_decode($body, true);

        return $request->url() === 'http://storefront.internal/api/revalidate'
            && hash_equals($expected, $request->header('X-Revalidate-Signature')[0])
            && $payload['site'] === 'fix-c.test'
            && in_array('product:'.$slug, $payload['tags'], true)
            && in_array('product:'.str_replace('-renamed', '', $slug), $payload['tags'], true);
    });

    expect($brand->run(fn () => OutboxMessage::query()->where('id', '>', $before)->where('event_type', 'storefront.revalidate')->whereNull('published_at')->count()))->toBe(0);
});
