<?php

declare(strict_types=1);

use Modules\Platform\Contracts\OnboardingCheck;
use Modules\Platform\Contracts\OnboardingChecks;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Support\LaunchChecklist;
use Tests\Support\TestPlatform;

/*
| Tenant onboarding checklist (spec §13 phase 6): computed from the brand's real state —
| landlord facts plus each module's own check, run inside the brand.
*/

final class ExplodingCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Exploding check';
    }

    public function hint(): string
    {
        return 'never shown';
    }

    public function passes(): bool
    {
        throw new RuntimeException('boom');
    }
}

final class BrandNameCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Runs inside the brand';
    }

    public function hint(): string
    {
        return 'Inside '.(string) tenant()?->getTenantKey();
    }

    public function passes(): bool
    {
        return tenant() !== null;
    }
}

it('lists every module check with its state, run inside the brand', function (): void {
    $brand = TestPlatform::brand(TestPlatform::BRAND_C);
    app(OnboardingChecks::class)->register(BrandNameCheck::class);
    app(OnboardingChecks::class)->register(ExplodingCheck::class);

    $items = collect(app(LaunchChecklist::class)->for($brand))->keyBy('label');

    expect($items->keys()->all())->toContain(
        'Storefront, admin and API addresses', 'Plan assigned', 'Theme chosen', 'Store email sender set',
        'Products on sale', 'Delivery method', 'Payment method', 'Home page published', 'Test order placed',
    )
        ->and($items['Storefront, admin and API addresses']['done'])->toBeTrue()
        ->and($items['Delivery method']['done'])->toBeTrue()          // seeded with every brand
        ->and($items['Home page published']['done'])->toBeTrue()      // seeded with every brand
        ->and($items['Runs inside the brand']['done'])->toBeTrue()
        ->and($items['Runs inside the brand']['hint'])->toBe('Inside '.$brand->id)
        // A failing check is "not done", never "done".
        ->and($items['Exploding check']['done'])->toBeFalse()
        ->and($items['Exploding check']['hint'])->toBe('Could not be checked: RuntimeException')
        ->and(tenant())->toBeNull();
});

it('shows the checklist to platform staff on the brands screen', function (): void {
    $user = PlatformUser::query()->create(['name' => 'Checklist viewer', 'email' => 'checklist-'.uniqid().'@platform.test', 'password' => 'platform-pass-99', 'role' => PlatformRole::Engineer, 'status' => 'active']);

    $this->actingAs($user, 'platform')
        ->get('http://platform.test/tenants')
        ->assertOk()
        ->assertSee('Launch checklist');
});
