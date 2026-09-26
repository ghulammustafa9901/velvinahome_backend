<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestPlatform;

/*
| Isolation channels 4 (cache) and 6 (storage), between sibling brands B and C.
|
| Cache isolation is proven against Redis — the production store. The array store cannot
| prove anything here: tenancy builds a fresh cache manager (and so a fresh, empty array
| store) every time it initialises.
*/

beforeEach(function (): void {
    if (! str_contains($this->name(), 'storage')) {
        try {
            Redis::connection('cache')->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }

        config(['cache.default' => 'redis']);
        app('cache')->forgetDriver('redis');
    }
});

it('scopes the same cache key per brand', function (): void {
    $key = 'iso-'.Str::random(8);

    TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($key): void {
        Cache::put($key, 'from-b', 60);
    });
    TestPlatform::brand(TestPlatform::BRAND_C)->run(function () use ($key): void {
        Cache::put($key, 'from-c', 60);
    });

    expect(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => Cache::get($key)))->toBe('from-b')
        ->and(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => Cache::get($key)))->toBe('from-c')
        ->and(Cache::get($key))->toBeNull();
});

it('flushes only the flushing brand\'s cache', function (): void {
    $key = 'iso-'.Str::random(8);

    TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($key): void {
        Cache::put($key, 'keep-me', 60);
    });
    TestPlatform::brand(TestPlatform::BRAND_C)->run(function () use ($key): void {
        Cache::put($key, 'drop-me', 60);
    });

    TestPlatform::brand(TestPlatform::BRAND_C)->run(function (): void {
        Cache::flush();
    });

    expect(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => Cache::get($key)))->toBe('keep-me')
        ->and(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => Cache::get($key)))->toBeNull();
});

it('scopes an injected cache repository per brand too', function (): void {
    $key = 'iso-injected-'.Str::random(8);

    TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($key): void {
        app(Repository::class)->put($key, 'from-b', 60);
    });

    expect(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => app(Repository::class)->get($key)))->toBeNull()
        ->and(app(Repository::class)->get($key))->toBeNull()
        ->and(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => app(Repository::class)->get($key)))->toBe('from-b');
});

it('prefixes storage per brand so a sibling cannot read the file', function (string $disk): void {
    $path = 'iso/'.Str::random(10).'.txt';

    $rootB = TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($disk, $path): string {
        Storage::disk($disk)->put($path, 'secret of brand b');

        return (string) config("filesystems.disks.{$disk}.root");
    });

    $rootC = TestPlatform::brand(TestPlatform::BRAND_C)->run(fn (): string => (string) config("filesystems.disks.{$disk}.root"));

    expect($rootB)->toContain(TestPlatform::brand(TestPlatform::BRAND_B)->id)
        ->and($rootC)->toContain(TestPlatform::brand(TestPlatform::BRAND_C)->id)
        ->and(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn (): bool => Storage::disk($disk)->exists($path)))->toBeFalse()
        ->and(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn (): bool => Storage::disk($disk)->exists($path)))->toBeTrue();

    TestPlatform::brand(TestPlatform::BRAND_B)->run(function () use ($disk, $path): void {
        Storage::disk($disk)->delete($path);
    });
})->with(['tenant_media', 'tenant_private', 'local']);
