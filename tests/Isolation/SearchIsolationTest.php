<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| Isolation channel 5 (search) and the search behaviour of ADR-0005: typo tolerance on words,
| exact SKUs, only published products, in_stock follows the ledger.
*/

/**
 * @return list<string> product ULIDs found
 */
function searchIn(string $slug, string $query): array
{
    return TestPlatform::brand($slug)->run(
        fn (): array => Product::search($query)->raw()['hits'] ?? [],
    ) === [] ? [] : array_map(
        fn (array $hit): string => $hit['document']['id'],
        TestPlatform::brand($slug)->run(fn (): array => Product::search($query)->raw()['hits']),
    );
}

beforeEach(function (): void {
    // Fully random: fixture brands keep products from earlier runs, and typo tolerance would
    // otherwise match a similar word left behind by another run.
    $this->word = 'zq'.strtolower(Str::random(12));
    $this->sku = 'SRCH-'.strtoupper(Str::random(8));
});

it('never returns one brand\'s products in a sibling brand\'s search', function (): void {
    $product = TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => TestCatalog::simpleProduct(['title' => 'Velvet '.$this->word.' cushion'], publish: true),
    );

    expect(searchIn(TestPlatform::BRAND_B, $this->word))->toContain($product->ulid)
        ->and(searchIn(TestPlatform::BRAND_C, $this->word))->toBe([])
        ->and(searchIn(TestPlatform::BRAND_A, $this->word))->toBe([]);
});

it('tolerates typos in titles', function (): void {
    $product = TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => TestCatalog::simpleProduct(['title' => 'Handwoven '.$this->word.' throw'], publish: true),
    );

    // One wrong letter in the middle of the distinctive word.
    $typo = substr($this->word, 0, 4).'x'.substr($this->word, 5);

    expect(searchIn(TestPlatform::BRAND_B, $typo))->toContain($product->ulid);
});

it('matches SKUs exactly, never by typo', function (): void {
    $product = TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => TestCatalog::simpleProduct(['sku' => $this->sku, 'title' => 'Plain mug '.$this->word], publish: true),
    );

    $mistyped = substr($this->sku, 0, -1).(str_ends_with($this->sku, 'Q') ? 'R' : 'Q');

    expect(searchIn(TestPlatform::BRAND_B, $this->sku))->toContain($product->ulid)
        ->and(searchIn(TestPlatform::BRAND_B, $mistyped))->not->toContain($product->ulid);
});

it('indexes only published products and removes archived ones', function (): void {
    $product = TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => TestCatalog::simpleProduct(['title' => 'Draft '.$this->word]),
    );

    expect(searchIn(TestPlatform::BRAND_B, $this->word))->not->toContain($product->ulid);

    TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => app(ChangeProductStatus::class)->handle($product, ProductStatus::Active));
    expect(searchIn(TestPlatform::BRAND_B, $this->word))->toContain($product->ulid);

    TestPlatform::brand(TestPlatform::BRAND_B)->run(fn () => app(ChangeProductStatus::class)->handle($product->refresh(), ProductStatus::Archived));
    expect(searchIn(TestPlatform::BRAND_B, $this->word))->not->toContain($product->ulid);
});

it('applies a brand\'s synonyms to that brand only', function (): void {
    $local = 'takia'.strtolower(Str::random(4));
    $product = TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => TestCatalog::simpleProduct(['title' => 'Velvet '.$this->word], publish: true),
    );
    TestPlatform::brand(TestPlatform::BRAND_C)->run(
        fn () => TestCatalog::simpleProduct(['title' => 'Velvet '.$this->word], publish: true),
    );

    TestPlatform::brand(TestPlatform::BRAND_B)->run(
        fn () => app(Modules\Catalog\Actions\SaveSearchSynonym::class)->handle(null, [$local, $this->word]),
    );

    expect(searchIn(TestPlatform::BRAND_B, $local))->toContain($product->ulid)
        ->and(searchIn(TestPlatform::BRAND_C, $local))->toBe([]);
});

it('keeps in_stock in the search document in step with the ledger', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $product = $brand->run(fn () => TestCatalog::simpleProduct(['title' => 'Stocked '.$this->word], publish: true));
    $inStock = fn (): bool => $brand->run(fn (): bool => Product::search($this->word)->raw()['hits'][0]['document']['in_stock']);

    expect($inStock())->toBeFalse();

    $brand->run(fn () => app(ReceiveStock::class)->handle(StockDocumentData::from([
        'locationUlid' => TestCatalog::defaultLocation()->ulid,
        'lines' => [['variantUlid' => $product->variants()->firstOrFail()->ulid, 'quantity' => 2]],
    ])));

    expect($inStock())->toBeTrue();
});
