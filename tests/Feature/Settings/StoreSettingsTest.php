<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Identity\Mail\CustomerPasswordResetMail;
use Modules\Settings\Actions\SaveStoreLogo;
use Modules\Settings\Actions\SaveStoreSettings;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Settings\Data\StoreSettingsData;
use Modules\Settings\Filament\Pages\StoreSettingsPage;
use Modules\Settings\Support\StoreLogo;
use Tests\Support\TestPlatform;
use Tests\Support\TestSettings;

/*
| Store identity is owned by the admin (single-store plan R1): name, storefront URL, logo,
| locale, theme, SEO, mail and feature flags are settings, saved through Actions that check
| settings.manage, and published to the storefront through GET /storefront/config.
*/

const SNAPSHOT_KEYS = [
    'store.name', 'store.url', 'store.logo', 'store.timezone', 'store.locale', 'store.country', 'store.contact_email', 'store.contact_phone',
    'theme.id', 'theme.tokens', 'tax.mode', 'seo.robots_allow_indexing', 'seo.title_suffix', 'mail.from_name', 'mail.from_address',
];

beforeEach(function (): void {
    Mail::fake();
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_A);
    $this->api = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_A).'/api/v1';
    $this->admin = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_A);
    $this->owner = TestPlatform::staff($this->brand, role: 'Owner');
    $this->snapshot = $this->brand->run(fn (): array => collect(SNAPSHOT_KEYS)->mapWithKeys(fn (string $key): array => [$key => app(TenantSettings::class)->get($key)])->all());
});

afterEach(function (): void {
    foreach ($this->snapshot as $key => $value) {
        TestSettings::set($this->brand, $key, $value);
    }

    foreach (['wishlist', 'reviews', 'blog'] as $flag) {
        TestSettings::feature($this->brand, $flag, false);
    }
});

/**
 * @param  array<string, mixed>  $overrides
 */
function storeSettings(array $overrides = []): StoreSettingsData
{
    return StoreSettingsData::validateAndCreate([
        'name' => 'Settings Test Store',
        'url' => 'https://shop.example.test/',
        'timezone' => 'Asia/Karachi',
        'locale' => 'en',
        'country' => 'PK',
        'contactEmail' => 'hello@shop.example.test',
        'contactPhone' => '0300 1234567',
        'themeId' => 'velvina-home',
        'themeTokens' => ['color-accent' => '#8a5a44'],
        'taxMode' => 'inclusive',
        'allowIndexing' => false,
        'titleSuffix' => 'Settings Test Store',
        'mailFromName' => 'Settings Test Store',
        'mailFromAddress' => 'orders@shop.example.test',
        'features' => ['wishlist' => true, 'reviews' => false, 'not-a-flag' => true],
        ...$overrides,
    ]);
}

/** A real 40×20 PNG on disk. */
function pngUpload(): string
{
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 138, 90, 68));
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

it('shows the settings page to an owner', function (): void {
    $this->actingAs($this->owner, 'staff')->get($this->admin.'/settings/store')->assertOk()->assertSee('Storefront URL');
});

it('keeps the settings page from staff without settings.manage', function (): void {
    $handler = TestPlatform::staff($this->brand, role: 'Order Handler');

    $this->actingAs($handler, 'staff')->get($this->admin.'/settings/store')->assertForbidden();
});

it('saves identity, theme, SEO, mail and flags, and publishes them to the storefront', function (): void {
    $this->brand->run(fn () => app(SaveStoreSettings::class)->handle(storeSettings(), $this->owner));

    $this->getJson($this->api.'/storefront/config')
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Settings Test Store')
        ->assertJsonPath('data.store.url', 'https://shop.example.test')
        ->assertJsonPath('data.theme.id', 'velvina-home')
        ->assertJsonPath('data.theme.tokens.color-accent', '#8a5a44')
        ->assertJsonPath('data.seo.allow_indexing', false)
        ->assertJsonPath('data.features.wishlist', true)
        ->assertJsonPath('data.features.reviews', false)
        ->assertJsonMissingPath('data.features.not-a-flag');

    expect($this->brand->run(fn (): ?string => app(TenantSettings::class)->get('mail.from_address')))->toBe('orders@shop.example.test');
});

it('saves through the admin page form', function (): void {
    tenancy()->initialize($this->brand);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->owner, 'staff');

    Livewire::test(StoreSettingsPage::class)
        ->fillForm(['name' => 'Typed In The Admin', 'url' => 'https://typed.example.test', 'features.blog' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(TenantSettings::class)->string('store.name'))->toBe('Typed In The Admin')
        ->and(app(TenantSettings::class)->storefrontUrl())->toBe('https://typed.example.test')
        ->and(app(TenantSettings::class)->get('features.blog'))->toBeTrue();
});

it('refuses a caller without settings.manage, inside the Action', function (): void {
    $handler = TestPlatform::staff($this->brand, role: 'Order Handler');

    $this->brand->run(fn () => app(SaveStoreSettings::class)->handle(storeSettings(), $handler));
})->throws(AuthorizationException::class);

it('rejects an invalid storefront URL and unsafe theme tokens', function (array $overrides): void {
    $this->brand->run(fn () => app(SaveStoreSettings::class)->handle(storeSettings($overrides), $this->owner));
})->with([
    'not a URL' => [['url' => 'shop.example.test']],
    'javascript URL' => [['url' => 'javascript:alert(1)']],
    'token breaking out' => [['themeTokens' => ['color-accent' => 'red; } body { display:none']]],
    'bad token name' => [['themeTokens' => ['Color Accent' => 'red']]],
])->throws(ValidationException::class);

it('uses the store URL setting for the password reset link', function (): void {
    TestSettings::set($this->brand, 'store.url', 'https://reset.example.test/');
    $email = 'st-'.Str::lower(Str::random(8)).'@example.test';
    $this->postJson($this->api.'/auth/register', ['email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Sana', 'last_name' => 'Iqbal'])->assertCreated();

    $this->postJson($this->api.'/auth/forgot-password', ['email' => $email])->assertStatus(202);

    Mail::assertQueued(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $mail): bool => str_starts_with($mail->link, 'https://reset.example.test/account/reset-password?'));
});

it('re-encodes an uploaded logo, publishes it, and replaces the previous file', function (): void {
    $first = $this->brand->run(fn (): ?string => app(SaveStoreLogo::class)->handle(pngUpload(), $this->owner));

    expect($first)->toStartWith(StoreLogo::DIRECTORY.'/logo-')->toEndWith('.webp')
        ->and($this->brand->run(fn (): string => (string) StoreLogo::disk()->get((string) $first)))->toStartWith('RIFF');

    $this->getJson($this->api.'/storefront/config')->assertJsonPath('data.store.logo_url', $this->brand->run(fn (): ?string => StoreLogo::url((string) $first)));

    $second = $this->brand->run(fn (): ?string => app(SaveStoreLogo::class)->handle(pngUpload(), $this->owner));

    expect($this->brand->run(fn (): bool => StoreLogo::disk()->exists((string) $first)))->toBeFalse()
        ->and($this->brand->run(fn (): bool => StoreLogo::disk()->exists((string) $second)))->toBeTrue();

    $this->brand->run(fn () => app(SaveStoreLogo::class)->handle(null, $this->owner));

    expect($this->brand->run(fn (): bool => StoreLogo::disk()->exists((string) $second)))->toBeFalse();
    $this->getJson($this->api.'/storefront/config')->assertJsonPath('data.store.logo_url', null);
});

it('refuses an SVG logo', function (): void {
    $svg = tempnam(sys_get_temp_dir(), 'logo').'.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');

    $this->brand->run(fn () => app(SaveStoreLogo::class)->handle($svg, $this->owner));
})->throws(ValidationException::class);
