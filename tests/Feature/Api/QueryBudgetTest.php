<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Actions\SaveCollection;
use Tests\Support\TestCommerce;

/*
| Performance review of hot endpoints (spec §13 Phase 4): the number of database queries must
| not grow with the number of items (no N+1), and stays within a fixed budget.
*/

beforeEach(function (): void {
    $this->api = 'http://localhost/api/v1';
});

/**
 * Queries against the database while running the callback.
 */
function databaseQueries(callable $callback): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if ($query->connectionName === 'pgsql') {
            $count++;
        }
    });

    $callback();

    return $count;
}

it('lists products in constant queries', function (): void {
    $slug = 'budget-'.Str::lower(Str::random(6));
    $variants = array_map(fn () => TestCommerce::stockedVariant(10_000, 2), range(1, 8));
    app(SaveCollection::class)->handle(null, $slug, array_map(fn ($variant) => $variant->product->ulid, $variants));

    $few = databaseQueries(fn () => $this->getJson($this->api.'/products?per_page=2')->assertOk());
    $many = databaseQueries(fn () => $this->getJson($this->api.'/products?per_page=8')->assertOk());
    $collection = databaseQueries(fn () => $this->getJson($this->api.'/collections/'.$slug)->assertOk());

    expect($many)->toBe($few)->toBeLessThanOrEqual(12)
        ->and($collection)->toBeLessThanOrEqual(14);
});

it('shows a product in a fixed number of queries', function (): void {
    $ulid = TestCommerce::stockedVariant(10_000, 2)->product->ulid;

    expect(databaseQueries(fn () => $this->getJson($this->api.'/products/'.$ulid)->assertOk()))->toBeLessThanOrEqual(15);
});

it('prices carts and quotes without per-line queries', function (): void {
    $variants = array_map(fn () => TestCommerce::stockedVariant(10_000, 5), range(1, 6));

    $cartWith = function (int $lines) use ($variants): array {
        $created = $this->flushHeaders()->postJson($this->api.'/carts')->assertCreated();
        $this->withHeader('X-Cart-Token', (string) $created->json('data.token'));

        foreach (array_slice($variants, 0, $lines) as $variant) {
            $this->postJson($this->api.'/carts/'.$created->json('data.id').'/items', ['variant_id' => $variant->ulid, 'quantity' => 1])->assertOk();
        }

        return [(string) $created->json('data.id'), (string) $created->json('data.token')];
    };

    [$small, $smallToken] = $cartWith(1);
    [$large, $largeToken] = $cartWith(6);

    $read = fn (string $cart, string $token): int => databaseQueries(fn () => $this->flushHeaders()->withHeader('X-Cart-Token', $token)->getJson($this->api.'/carts/'.$cart)->assertOk());
    $quote = fn (string $cart, string $token): int => databaseQueries(fn () => $this->flushHeaders()->withHeader('X-Cart-Token', $token)
        ->postJson($this->api.'/checkout/quote', ['cart_id' => $cart, 'shipping_address' => TestCommerce::address(), 'shipping_method' => 'standard'])->assertOk());

    // Warm per-request caches (settings, tax rates) so only the per-line cost is compared.
    $read($small, $smallToken);
    $quote($small, $smallToken);

    expect($read($large, $largeToken))->toBe($read($small, $smallToken))->toBeLessThanOrEqual(20)
        ->and($quote($large, $largeToken))->toBe($quote($small, $smallToken))->toBeLessThanOrEqual(25);
});
