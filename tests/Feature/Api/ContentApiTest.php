<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Content\Actions\ChangePageStatus;
use Modules\Content\Actions\SaveMenu;
use Modules\Content\Actions\SavePage;
use Tests\Support\TestCatalog;
use Tests\Support\TestPlatform;

/*
| Content (spec §5 page builder): pages of allow-listed sections, menus, sitemap data.
*/

beforeEach(function (): void {
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1';
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
});

it('serves the seeded home page and menus', function (): void {
    $this->getJson($this->api.'/pages/home')
        ->assertOk()
        ->assertJsonPath('data.path', '/')
        ->assertJsonPath('data.sections.0.type', 'hero');

    $this->getJson($this->api.'/menus/main')->assertOk()->assertJsonPath('data.items.0.url', '/');
    $this->getJson($this->api.'/menus/nope')->assertNotFound();
});

it('publishes a page built from sections and hides drafts', function (): void {
    $slug = 'about-'.Str::lower(Str::random(6));

    $page = $this->brand->run(fn () => app(SavePage::class)->handle(null, 'About us', $slug, [
        ['type' => 'rich_text', 'data' => ['heading' => 'Our story', 'body' => "Since 2010.\nHand made."]],
        ['type' => 'faq', 'data' => ['items' => [['question' => 'Do you ship?', 'answer' => 'Nationwide.']]]],
    ], 'About', 'Who we are'));

    $this->getJson($this->api.'/pages/'.$slug)->assertNotFound();

    $this->brand->run(fn () => app(ChangePageStatus::class)->handle($page, 'published'));

    $this->getJson($this->api.'/pages/'.$slug)
        ->assertOk()
        ->assertJsonPath('data.sections.1.data.items.0.answer', 'Nationwide.')
        ->assertJsonPath('data.meta.description', 'Who we are');
});

it('refuses unknown section types, unknown fields and unsafe links', function (array $section): void {
    expect(fn () => $this->brand->run(fn () => app(SavePage::class)->handle(null, 'Bad '.Str::random(4), null, [$section])))
        ->toThrow(ValidationException::class);
})->with([
    'script section' => [['type' => 'html', 'data' => ['html' => '<script>alert(1)</script>']]],
    'extra field' => [['type' => 'hero', 'data' => ['heading' => 'Hi', 'onclick' => 'x']]],
    'javascript link' => [['type' => 'hero', 'data' => ['heading' => 'Hi', 'cta_label' => 'Go', 'cta_url' => 'javascript:alert(1)']]],
    'protocol-relative link' => [['type' => 'banner', 'data' => ['image_url' => '//evil.example/x.png', 'alt' => 'x']]],
]);

it('validates menu links the same way', function (): void {
    expect(fn () => $this->brand->run(fn () => app(SaveMenu::class)->handle(null, 'bad-'.Str::lower(Str::random(4)), 'Bad', [
        ['label' => 'Evil', 'url' => 'javascript:alert(1)'],
    ])))->toThrow(ValidationException::class);
});

it('lists sitemap chunks and their paths', function (): void {
    $product = $this->brand->run(fn () => TestCatalog::simpleProduct(publish: true));

    $chunks = collect($this->getJson($this->api.'/sitemap')->assertOk()->json('data'))->pluck('name')->all();
    expect($chunks)->toContain('products-1', 'pages-1');

    $paths = collect($this->getJson($this->api.'/sitemap/products-1')->assertOk()->json('data'))->pluck('path')->all();
    expect($paths)->toContain('/products/'.$product->slug);

    expect(collect($this->getJson($this->api.'/sitemap/pages-1')->json('data'))->pluck('path')->all())->toContain('/');
    $this->getJson($this->api.'/sitemap/products-999')->assertNotFound();
});
