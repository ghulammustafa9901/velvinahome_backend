<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Fixtures\UlidFixtureModel;

beforeEach(function (): void {
    Schema::connection('sqlite')->create('ulid_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->char('ulid', 26)->unique();
        $table->string('name')->nullable();
    });
});

afterEach(function (): void {
    Schema::connection('sqlite')->dropIfExists('ulid_fixtures');
});

it('assigns a ULID on create', function (): void {
    $model = UlidFixtureModel::create(['name' => 'first']);

    expect($model->ulid)->toBeString()->toHaveLength(26);
});

it('keeps an explicitly provided ULID', function (): void {
    $ulid = UlidFixtureModel::generateUlid();

    $model = UlidFixtureModel::create(['ulid' => $ulid, 'name' => 'explicit']);

    expect($model->ulid)->toBe($ulid);
});

it('generates sortable ULIDs', function (): void {
    // ULIDs are lexicographically sortable by creation time (ADR-0008), which is what makes
    // them usable for cursor pagination and log correlation.
    $first = UlidFixtureModel::generateUlid();
    $second = UlidFixtureModel::generateUlid();

    expect($second >= $first)->toBeTrue();
});

it('resolves route bindings by ULID, never by the internal id', function (): void {
    $model = UlidFixtureModel::create(['name' => 'routed']);

    expect($model->getRouteKeyName())->toBe('ulid')
        ->and($model->getRouteKey())->toBe($model->ulid);
});

it('queries by ULID through the scope', function (): void {
    $model = UlidFixtureModel::create(['name' => 'scoped']);

    $found = UlidFixtureModel::query()->whereUlid($model->ulid)->firstOrFail();

    expect($found->getKey())->toBe($model->getKey());
});

it('gives each record a distinct ULID', function (): void {
    $ulids = collect(range(1, 25))
        ->map(fn (int $i): string => (string) UlidFixtureModel::create(['name' => "row {$i}"])->ulid)
        ->all();

    expect(array_unique($ulids))->toHaveCount(25);
});
