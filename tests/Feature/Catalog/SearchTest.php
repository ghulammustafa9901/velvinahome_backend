<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\SaveSearchSynonym;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Tests\Support\TestCatalog;

/*
| Search behaviour (ADR-0005, ADR-0014): typo tolerance on words, exact SKUs, only published
| products, synonyms, in_stock follows the ledger.
*/

/**
 * @return list<string> product ULIDs found
 */
function searchFor(string $query): array
{
    return array_map(
        fn (array $hit): string => $hit['document']['id'],
        Product::search($query)->raw()['hits'] ?? [],
    );
}

beforeEach(function (): void {
    // Fully random: the test store keeps products from earlier runs, and typo tolerance would
    // otherwise match a similar word left behind by another run.
    $this->word = 'zq'.strtolower(Str::random(12));
    $this->sku = 'SRCH-'.strtoupper(Str::random(8));
});

it('searches the store\'s own collection', function (): void {
    $product = TestCatalog::simpleProduct(['title' => 'Velvet '.$this->word.' cushion'], publish: true);

    expect((new Product)->searchableAs())->toBe(config('scout.prefix').'products')
        ->and(searchFor($this->word))->toContain($product->ulid);
});

it('tolerates typos in titles', function (): void {
    $product = TestCatalog::simpleProduct(['title' => 'Handwoven '.$this->word.' throw'], publish: true);

    // One wrong letter in the middle of the distinctive word.
    $typo = substr($this->word, 0, 4).'x'.substr($this->word, 5);

    expect(searchFor($typo))->toContain($product->ulid);
});

it('matches SKUs exactly, never by typo', function (): void {
    $product = TestCatalog::simpleProduct(['sku' => $this->sku, 'title' => 'Plain mug '.$this->word], publish: true);

    $mistyped = substr($this->sku, 0, -1).(str_ends_with($this->sku, 'Q') ? 'R' : 'Q');

    expect(searchFor($this->sku))->toContain($product->ulid)
        ->and(searchFor($mistyped))->not->toContain($product->ulid);
});

it('indexes only published products and removes archived ones', function (): void {
    $product = TestCatalog::simpleProduct(['title' => 'Draft '.$this->word]);

    expect(searchFor($this->word))->not->toContain($product->ulid);

    app(ChangeProductStatus::class)->handle($product, ProductStatus::Active);
    expect(searchFor($this->word))->toContain($product->ulid);

    app(ChangeProductStatus::class)->handle($product->refresh(), ProductStatus::Archived);
    expect(searchFor($this->word))->not->toContain($product->ulid);
});

it('applies the store\'s synonyms', function (): void {
    $local = 'takia'.strtolower(Str::random(4));
    $product = TestCatalog::simpleProduct(['title' => 'Velvet '.$this->word], publish: true);

    expect(searchFor($local))->not->toContain($product->ulid);

    app(SaveSearchSynonym::class)->handle(null, [$local, $this->word]);

    expect(searchFor($local))->toContain($product->ulid);
});

it('keeps in_stock in the search document in step with the ledger', function (): void {
    $product = TestCatalog::simpleProduct(['title' => 'Stocked '.$this->word], publish: true);
    $inStock = fn (): bool => Product::search($this->word)->raw()['hits'][0]['document']['in_stock'];

    expect($inStock())->toBeFalse();

    app(ReceiveStock::class)->handle(StockDocumentData::from([
        'locationUlid' => TestCatalog::defaultLocation()->ulid,
        'lines' => [['variantUlid' => $product->variants()->firstOrFail()->ulid, 'quantity' => 2]],
    ]));

    expect($inStock())->toBeTrue();
});
