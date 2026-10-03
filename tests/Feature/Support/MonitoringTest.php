<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Support\Monitoring\PostHogErrors;
use Tests\Support\TestPlatform;

/*
| Monitoring (spec §9, PostHog per owner decision): errors are reported scrubbed and
| deduplicated; the health endpoint tells monitors when something is down.
*/

beforeEach(function (): void {
    config(['platform.monitoring.posthog.key' => 'phc_test_only_key', 'platform.monitoring.posthog.host' => 'https://posthog.test']);
    Http::fake(['posthog.test/*' => Http::response(['status' => 1])]);
});

it('reports an error to PostHog without personal data', function (): void {
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
    $unique = Str::random(8);

    app(PostHogErrors::class)->report(new RuntimeException("Order for ayesha.{$unique}@example.test failed, phone 03001234567"));

    Http::assertSent(function (HttpRequest $request) use ($unique): bool {
        $body = $request->data();
        $exception = $body['properties']['$exception_list'][0];

        return $request->url() === 'https://posthog.test/i/v0/e/'
            && $body['api_key'] === 'phc_test_only_key'
            && $body['event'] === '$exception'
            && $body['distinct_id'] === 'platform-api:tenant:'.tenant()?->getTenantKey()
            && $exception['type'] === RuntimeException::class
            && ! str_contains($exception['value'], $unique) && str_contains($exception['value'], '[email]') && str_contains($exception['value'], '[number]')
            && $exception['stacktrace']['frames'][0]['filename'] === 'tests/Feature/Support/MonitoringTest.php'
            && ! str_contains(json_encode($body), str_replace('\\', '/', base_path()));
    });
});

it('never sends SQL or its bindings', function (): void {
    $error = new QueryException('pgsql', 'select * from customers where email = ?', ['secret-'.Str::random(6).'@example.test'], new PDOException('SQLSTATE[23505]: duplicate'));
    $error->errorInfo = ['23505'];

    app(PostHogErrors::class)->report($error);

    Http::assertSent(fn (HttpRequest $request): bool => $request->data()['properties']['$exception_list'][0]['value'] === 'Database error (SQLSTATE 23505)'
        && ! str_contains(json_encode($request->data()), 'customers'));
});

it('reports the same error once a minute, and nothing without a key', function (): void {
    $error = new LogicException('Same place '.Str::random(6));
    Cache::flush();

    app(PostHogErrors::class)->report($error);
    app(PostHogErrors::class)->report($error);
    Http::assertSentCount(1);

    config(['platform.monitoring.posthog.key' => '']);
    Cache::flush();
    app(PostHogErrors::class)->report(new LogicException('Off'));
    Http::assertSentCount(1);
});

it('reports unhandled exceptions through the framework handler', function (): void {
    Cache::flush();
    report(new DomainException('Through the handler '.Str::random(6)));

    Http::assertSent(fn (HttpRequest $request): bool => $request->data()['properties']['$exception_list'][0]['type'] === DomainException::class);
});

it('tells monitors the platform is healthy, with detail only for the token', function (): void {
    $failed = DB::connection('landlord')->table('failed_jobs');
    $failed->where('failed_at', '>=', now()->subHour())->update(['failed_at' => now()->subDay()]);

    $this->get('http://platform.test/health')->assertOk()->assertExactJson(['status' => 'ok']);

    $this->get('http://platform.test/health', ['X-Health-Token' => config('platform.monitoring.health_token')])
        ->assertOk()
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonPath('checks.search', true);

    // Any host the application answers on; the details still need the token.
    $this->get('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('answers 503 when jobs failed in the last hour', function (): void {
    DB::connection('landlord')->table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'test', 'failed_at' => now(),
    ]);

    $this->get('http://platform.test/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);

    DB::connection('landlord')->table('failed_jobs')->where('exception', 'test')->delete();
});
