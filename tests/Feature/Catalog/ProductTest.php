<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Actions\ChangeProductStatus;
use Modules\Catalog\Actions\CreateProduct;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Data\ProductData;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Catalog\Enums\ProductStatus;
use Modules\Seo\Contracts\Seo;
use Modules\Support\Enums\Currency;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| Spec §6.3/§6.4: every sellable item is a variant; SKUs unique; option combinations unique;
| slug changes leave a 301; permissions enforced inside Actions.
*/

beforeEach(function (): void {
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
});

it('gives a simple product exactly one default variant, priced in the store currency', function (): void {
    $product = TestCatalog::simpleProduct(['priceAmount' => 129900]);
    $variants = $product->variants()->get();

    expect($variants)->toHaveCount(1)
        ->and($variants[0]->is_default)->toBeTrue()
        ->and($variants[0]->price->amount)->toBe(129900)
        ->and($variants[0]->price->currency)->toBe(Currency::PKR)
        ->and($product->status)->toBe(ProductStatus::Draft);
});

it('builds a variant matrix from options', function (): void {
    $suffix = strtoupper(Str::random(5));
    $variants = [];
    foreach (['Red', 'Blue'] as $color) {
        foreach (['S', 'M'] as $size) {
            $variants[] = ['sku' => "TEE-{$suffix}-{$color}-{$size}", 'priceAmount' => 199900, 'optionValues' => ['Color' => $color, 'Size' => $size]];
        }
    }

    $product = app(CreateProduct::class)->handle(ProductData::from([
        'title' => 'Tee '.$suffix,
        'options' => [['name' => 'Color', 'values' => ['Red', 'Blue']], ['name' => 'Size', 'values' => ['S', 'M']]],
        'variants' => $variants,
    ]));

    $signatures = $product->variants()->pluck('option_signature')->all();

    expect($signatures)->toHaveCount(4)
        ->and($signatures)->toContain('color=red|size=s', 'color=blue|size=m')
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('rejects invalid variant matrices', function (array $variants): void {
    app(CreateProduct::class)->handle(ProductData::from([
        'title' => 'Bad '.Str::random(5),
        'options' => [['name' => 'Color', 'values' => ['Red', 'Blue']]],
        'variants' => $variants,
    ]));
})->throws(ValidationException::class)->with([
    'duplicate combination' => [[
        ['sku' => 'DUP-A-'.Str::random(6), 'priceAmount' => 100, 'optionValues' => ['Color' => 'Red']],
        ['sku' => 'DUP-B-'.Str::random(6), 'priceAmount' => 100, 'optionValues' => ['Color' => 'Red']],
    ]],
    'unknown value' => [[['sku' => 'UNK-'.Str::random(6), 'priceAmount' => 100, 'optionValues' => ['Color' => 'Green']]]],
    'missing option' => [[['sku' => 'MIS-'.Str::random(6), 'priceAmount' => 100, 'optionValues' => []]]],
    'compare-at not above price' => [[['sku' => 'CMP-'.Str::random(6), 'priceAmount' => 100, 'compareAtPriceAmount' => 100, 'optionValues' => ['Color' => 'Red']]]],
]);

it('keeps SKUs unique regardless of case', function (): void {
    $sku = 'UNIQ-'.strtoupper(Str::random(8));
    TestCatalog::simpleProduct(['sku' => $sku]);

    TestCatalog::simpleProduct(['sku' => strtolower($sku)]);
})->throws(ValidationException::class);

it('leaves a 301 when a slug changes, without chains or loops', function (): void {
    $product = TestCatalog::simpleProduct();
    $original = $product->slug;
    $update = fn (string $slug) => app(UpdateProductDetails::class)->handle($product, new ProductDetailsData(title: $product->title, slug: $slug));

    $update($original.'-v2');
    $update($original.'-v3');

    $seo = app(Seo::class);

    expect($seo->resolve('/products/'.$original)?->toPath)->toBe('/products/'.$original.'-v3')
        ->and($seo->resolve('/products/'.$original.'-v2')?->toPath)->toBe('/products/'.$original.'-v3');

    // Going back to the original slug removes the redirect away from it.
    $update($original);
    expect($seo->resolve('/products/'.$original))->toBeNull()
        ->and($seo->resolve('/products/'.$original.'-v3')?->toPath)->toBe('/products/'.$original);
});

it('separates preparing a product from publishing it', function (): void {
    $editor = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Content Editor');
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));

    $product = app(CreateProduct::class)->handle(ProductData::from([
        'title' => 'Editor made '.Str::random(5),
        'variants' => [['sku' => 'ED-'.Str::random(8), 'priceAmount' => 100]],
    ]), $editor);

    expect(fn () => app(ChangeProductStatus::class)->handle($product, ProductStatus::Active, $editor))
        ->toThrow(AuthorizationException::class);
});

it('refuses catalog changes to roles without products.manage', function (): void {
    $handler = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Order Handler');
    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));

    app(CreateProduct::class)->handle(ProductData::from([
        'title' => 'Nope', 'variants' => [['sku' => 'NO-'.Str::random(8), 'priceAmount' => 100]],
    ]), $handler);
})->throws(AuthorizationException::class);
