<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Catalog\Actions\SaveCategory;
use Modules\Catalog\Actions\UpdateProductDetails;
use Modules\Catalog\Data\ProductDetailsData;
use Modules\Inventory\Actions\ReceiveStock;
use Modules\Inventory\Data\StockDocumentData;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| Storefront API v1 catalog endpoints (api-conventions §2, §4, §5, §11).
*/

beforeEach(function (): void {
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1';
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $this->word = 'quillon'.strtolower(Str::random(4));
});

it('serves a product with ULIDs, money objects and live stock, never internal ids', function (): void {
    $product = $this->brand->run(function () {
        $product = TestCatalog::simpleProduct(['title' => 'Linen '.$this->word, 'priceAmount' => 249900], publish: true);
        app(ReceiveStock::class)->handle(StockDocumentData::from([
            'locationUlid' => TestCatalog::defaultLocation()->ulid,
            'lines' => [['variantUlid' => $product->variants()->firstOrFail()->ulid, 'quantity' => 5]],
        ]));

        return $product;
    });

    $response = $this->getJson($this->api.'/products/'.$product->ulid)
        ->assertOk()
        ->assertJsonPath('data.id', $product->ulid)
        ->assertJsonPath('data.price.amount', 249900)
        ->assertJsonPath('data.price.currency', 'PKR')
        ->assertJsonPath('data.price.formatted', 'Rs 2,499')
        ->assertJsonPath('data.in_stock', true)
        ->assertJsonPath('data.variants.0.in_stock', true);

    expect(json_encode($response->json()))->not->toContain('"id":'.$product->id.',')
        ->and($response->headers->get('Cache-Control'))->toContain('s-maxage');
});

it('hides drafts and answers not_found in the standard error envelope', function (): void {
    $draft = $this->brand->run(fn () => TestCatalog::simpleProduct());

    $this->getJson($this->api.'/products/'.$draft->ulid)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('follows a changed slug with a 301 to the current one', function (): void {
    $product = $this->brand->run(fn () => TestCatalog::simpleProduct(['title' => 'Moved '.$this->word], publish: true));
    $old = $product->slug;

    $this->brand->run(fn () => app(UpdateProductDetails::class)->handle($product, new ProductDetailsData(title: $product->title, slug: $old.'-new')));

    $this->getJson($this->api.'/products/by-slug/'.$old)
        ->assertStatus(301)
        ->assertJsonPath('redirect.to_path', '/products/'.$old.'-new')
        ->assertHeader('Location', '/api/v1/products/by-slug/'.$old.'-new');

    $this->getJson($this->api.'/products/by-slug/'.$old.'-new')->assertOk()->assertJsonPath('data.id', $product->ulid);

    $this->getJson($this->api.'/redirects/resolve?path=/products/'.$old)
        ->assertOk()
        ->assertJsonPath('data.to_path', '/products/'.$old.'-new');
});

it('lists products with cursor pagination and a category filter including subcategories', function (): void {
    [$parent, $products] = $this->brand->run(function () {
        $parent = app(SaveCategory::class)->handle(null, 'Parent '.$this->word);
        $child = app(SaveCategory::class)->handle(null, 'Child '.$this->word, $parent->ulid);
        $products = [];
        foreach (range(1, 3) as $i) {
            $products[] = TestCatalog::simpleProduct(['title' => "Item {$i} ".$this->word, 'categoryUlids' => [$child->ulid]], publish: true);
        }

        return [$parent, $products];
    });

    $first = $this->getJson($this->api.'/products?per_page=2&filter[category]='.$parent->slug)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.has_more', true);

    $second = $this->getJson((string) $first->json('links.next'))->assertOk()->assertJsonPath('meta.has_more', false);

    $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
    expect($ids)->toEqualCanonicalizing(array_map(fn ($p) => $p->ulid, $products));
});

it('rejects unknown filters and sorts instead of ignoring them', function (): void {
    $this->getJson($this->api.'/products?filter[colour]=red')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    $this->getJson($this->api.'/products?sort=price')->assertUnprocessable();
    $this->getJson($this->api.'/search?q=x&sort=cheapest')->assertUnprocessable();
});

it('searches with facets and suggests as you type', function (): void {
    $product = $this->brand->run(fn () => TestCatalog::simpleProduct(['title' => 'Embroidered '.$this->word.' runner'], publish: true));

    $this->getJson($this->api.'/search?q='.$this->word.'&filter[in_stock]=false')
        ->assertOk()
        ->assertJsonPath('data.0.id', $product->ulid)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure(['meta' => ['facets' => ['brand', 'in_stock', 'price']]]);

    $this->getJson($this->api.'/search/suggest?q='.substr($this->word, 0, 6))
        ->assertOk()
        ->assertJsonFragment(['id' => $product->ulid]);

    // The sibling brand's API knows nothing about it.
    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/search?q='.$this->word)
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('serves the category tree with breadcrumbs', function (): void {
    $grandchild = $this->brand->run(function () {
        $a = app(SaveCategory::class)->handle(null, 'Tree A '.$this->word);
        $b = app(SaveCategory::class)->handle(null, 'Tree B '.$this->word, $a->ulid);

        return app(SaveCategory::class)->handle(null, 'Tree C '.$this->word, $b->ulid);
    });

    $this->getJson($this->api.'/categories/'.$grandchild->slug)
        ->assertOk()
        ->assertJsonPath('data.breadcrumbs.0.name', 'Tree A '.$this->word)
        ->assertJsonPath('data.breadcrumbs.1.name', 'Tree B '.$this->word);

    $this->getJson($this->api.'/categories')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'slug', 'children']]]);
});
