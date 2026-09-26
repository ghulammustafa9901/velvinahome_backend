<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Platform\Actions\ChangeTenantStatus;
use Modules\Platform\Actions\DispatchToActiveTenants;
use Modules\Platform\Enums\TenantStatus;
use Modules\Platform\Models\Tenant;
use Tests\Support\Jobs\WriteMarkerJob;
use Tests\Support\TestPlatform;

/*
| Isolation channels 7 (queues) and 8 (scheduler).
*/

function markerIn(string $slug, string $marker): mixed
{
    return TestPlatform::brand($slug)->run(fn () => DB::table('settings')->where('group', 'probe')->where('key', $marker)->value('value'));
}

function redisAvailable(): bool
{
    try {
        Redis::connection('default')->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('runs a queued job in the brand that dispatched it, even when the worker is elsewhere', function (): void {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis is not available.');
    }

    $marker = 'queue-'.Str::lower(Str::random(8));
    $queue = 'isolation-'.Str::lower(Str::random(6));

    TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($marker, $queue): void {
        WriteMarkerJob::dispatch($marker)->onConnection('redis')->onQueue($queue);
    });

    // The worker starts inside the *wrong* sibling brand, to prove the job re-initialises
    // its own tenant rather than inheriting whatever context the worker happens to be in.
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_C));
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--stop-when-empty' => true]);
    tenancy()->end();

    expect(markerIn(TestPlatform::BRAND_B, $marker))->toBe(json_encode(TestPlatform::brand(TestPlatform::BRAND_B)->id))
        ->and(markerIn(TestPlatform::BRAND_C, $marker))->toBeNull();
});

it('fails a tenant job that arrives without tenant context instead of running it anywhere', function (): void {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis is not available.');
    }

    $marker = 'orphan-'.Str::lower(Str::random(8));
    $queue = 'isolation-'.Str::lower(Str::random(6));

    WriteMarkerJob::dispatch($marker)->onConnection('redis')->onQueue($queue);

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--stop-when-empty' => true]);

    $failed = DB::connection('landlord')->table('failed_jobs')->where('queue', $queue)->first();

    expect($failed)->not->toBeNull()
        ->and($failed->exception)->toContain('requires tenant context');

    foreach ([TestPlatform::BRAND_A, TestPlatform::BRAND_B, TestPlatform::BRAND_C] as $slug) {
        expect(markerIn($slug, $marker))->toBeNull();
    }
});

it('fans scheduled work out once per active brand, in that brand\'s context', function (): void {
    $marker = 'sched-'.Str::lower(Str::random(8));

    $report = app(DispatchToActiveTenants::class)->handle(fn () => WriteMarkerJob::dispatch($marker));

    foreach ([TestPlatform::BRAND_A, TestPlatform::BRAND_B, TestPlatform::BRAND_C] as $slug) {
        expect($report[$slug] ?? null)->toBe('dispatched')
            ->and(markerIn($slug, $marker))->toBe(json_encode(TestPlatform::brand($slug)->id));
    }
});

it('skips suspended brands and keeps going past a failing one', function (): void {
    $marker = 'sched-'.Str::lower(Str::random(8));
    $brandA = TestPlatform::brand(TestPlatform::BRAND_A);

    app(ChangeTenantStatus::class)->handle($brandA, TenantStatus::Suspended, 'scheduler test');

    try {
        $report = app(DispatchToActiveTenants::class)->handle(function (Tenant $tenant) use ($marker): void {
            if ($tenant->slug === TestPlatform::BRAND_B) {
                throw new RuntimeException('brand B is broken');
            }

            WriteMarkerJob::dispatch($marker);
        });
    } finally {
        app(ChangeTenantStatus::class)->handle($brandA->refresh(), TenantStatus::Active, 'scheduler test');
    }

    expect($report)->not->toHaveKey(TestPlatform::BRAND_A)
        ->and($report[TestPlatform::BRAND_B])->toStartWith('failed')
        ->and($report[TestPlatform::BRAND_C])->toBe('dispatched')
        ->and(markerIn(TestPlatform::BRAND_C, $marker))->not->toBeNull()
        ->and(markerIn(TestPlatform::BRAND_A, $marker))->toBeNull();
});
