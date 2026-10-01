<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Content\Actions\SaveArticle;
use Modules\Content\Actions\SaveContentEntry;
use Modules\Content\Actions\SaveContentType;
use Modules\Content\Models\Article;
use Modules\Platform\Contracts\FeatureFlags;
use Modules\Platform\Models\TenantFeatureFlag;
use Modules\Seo\Contracts\Seo;
use Tests\Support\Contract;
use Tests\Support\TestPlatform;

/*
| Blog (feature `blog`) and staff-defined content types (spec §13 phase 6): plain text only,
| allow-listed field kinds, drafts never public, moved posts redirect.
*/

beforeEach(function (): void {
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_C);
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1';
    $this->tag = 'tag-'.Str::lower(Str::random(6));
});

afterEach(function (): void {
    app('db')->connection('landlord')->table('tenant_feature_flags')->where('tenant_id', $this->brand->id)->where('key', 'blog')->delete();
    app(FeatureFlags::class)->forget($this->brand->id);
});

function blogOn(object $test): void
{
    TenantFeatureFlag::query()->create(['tenant_id' => $test->brand->id, 'key' => 'blog', 'enabled' => true]);
    app(FeatureFlags::class)->forget($test->brand->id);
}

function post(object $test, string $title, bool $published = true): Article
{
    return $test->brand->run(fn () => app(SaveArticle::class)->handle(null, $title, null, "First paragraph.\n\nSecond paragraph.", 'Short summary', '/media/cover.jpg', 'Maham', [$test->tag, 'Home'], $published));
}

it('keeps the blog behind its feature flag', function (): void {
    $this->getJson($this->api.'/articles')->assertForbidden()->assertJsonPath('error.code', 'feature_disabled');
});

it('lists published posts newest first, filters by tag, and hides drafts', function (): void {
    blogOn($this);
    $first = post($this, 'Caring for linen '.$this->tag);
    $second = post($this, 'Choosing cushions '.$this->tag);
    post($this, 'Draft thoughts '.$this->tag, published: false);

    $list = $this->getJson($this->api.'/articles?filter[tag]='.$this->tag.'&per_page=10')->assertOk();
    Contract::assert($list, 'GET /v1/articles');

    expect(array_column($list->json('data'), 'slug'))->toBe([$second->slug, $first->slug])
        ->and($list->json('data.0.tags'))->toBe([$this->tag, 'home']);

    $show = $this->getJson($this->api.'/articles/'.$first->slug)->assertOk()
        ->assertJsonPath('data.body', "First paragraph.\n\nSecond paragraph.")
        ->assertJsonPath('data.path', '/blog/'.$first->slug);
    Contract::assert($show, 'GET /v1/articles/{slug}');

    $draft = $this->brand->run(fn () => Article::query()->where('title', 'Draft thoughts '.$this->tag)->value('slug'));
    $this->getJson($this->api.'/articles/'.$draft)->assertNotFound();
});

it('redirects the old address of a published post that moved', function (): void {
    $article = post($this, 'Moving post '.$this->tag);
    $old = $article->path();

    $this->brand->run(fn () => app(SaveArticle::class)->handle($article, $article->title, 'moved-'.$this->tag, $article->body, published: true));

    expect($this->brand->run(fn () => app(Seo::class)->resolve($old)?->toPath))->toBe('/blog/moved-'.$this->tag);
});

it('refuses unsafe covers', function (): void {
    expect(fn () => $this->brand->run(fn () => app(SaveArticle::class)->handle(null, 'Bad '.$this->tag, null, 'Body', coverImageUrl: 'javascript:alert(1)')))
        ->toThrow(ValidationException::class);
});

it('serves content type entries with only their declared, checked fields', function (): void {
    $key = 'faq-'.Str::lower(Str::random(5));

    $this->brand->run(function () use ($key): void {
        $type = app(SaveContentType::class)->handle(null, $key, 'FAQ', [
            ['key' => 'answer', 'label' => 'Answer', 'kind' => 'textarea', 'required' => true],
            ['key' => 'link', 'label' => 'Read more', 'kind' => 'url', 'required' => false],
            ['key' => 'featured', 'label' => 'Featured', 'kind' => 'boolean', 'required' => false],
        ]);

        app(SaveContentEntry::class)->handle($type, null, 'Do you deliver?', null, ['answer' => 'Yes, nationwide.', 'featured' => '1'], published: true, position: 2);
        app(SaveContentEntry::class)->handle($type, null, 'How do I pay?', null, ['answer' => 'Cash on delivery.', 'link' => '/pages/payments'], published: true, position: 1);
        app(SaveContentEntry::class)->handle($type, null, 'Hidden draft', null, ['answer' => 'Not yet.']);

        expect(fn () => app(SaveContentEntry::class)->handle($type, null, 'Bad', null, ['answer' => 'x', 'script' => '<b>']))->toThrow(ValidationException::class)
            ->and(fn () => app(SaveContentEntry::class)->handle($type, null, 'Bad link', null, ['answer' => 'x', 'link' => 'javascript:alert(1)']))->toThrow(ValidationException::class)
            ->and(fn () => app(SaveContentEntry::class)->handle($type, null, 'Missing', null, []))->toThrow(ValidationException::class);
    });

    $list = $this->getJson($this->api.'/content/'.$key)->assertOk();
    Contract::assert($list, 'GET /v1/content/{type}');

    expect(array_column($list->json('data'), 'title'))->toBe(['How do I pay?', 'Do you deliver?'])
        ->and($list->json('data.1.values'))->toBe(['answer' => 'Yes, nationwide.', 'featured' => true])
        ->and($list->json('meta.type.name'))->toBe('FAQ');

    $this->getJson($this->api.'/content/'.$key.'/do-you-deliver')->assertOk()->assertJsonPath('data.values.answer', 'Yes, nationwide.');
    $this->getJson($this->api.'/content/'.$key.'/hidden-draft')->assertNotFound();
    $this->getJson($this->api.'/content/no-such-type')->assertNotFound();
});

it('refuses field kinds outside the allow-list', function (): void {
    expect(fn () => $this->brand->run(fn () => app(SaveContentType::class)->handle(null, 'bad-'.Str::lower(Str::random(4)), 'Bad', [['key' => 'x', 'label' => 'X', 'kind' => 'html']])))
        ->toThrow(ValidationException::class);
});

it('shows the blog and content screens to content editors', function (string $path): void {
    $this->actingAs(TestPlatform::staff($this->brand, role: 'Content Editor'), 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).$path)->assertOk();
})->with(['/articles', '/content-types', '/content-entries']);
