<?php

declare(strict_types=1);

use Modules\Settings\Contracts\OnboardingCheck;
use Modules\Settings\Contracts\OnboardingChecks;
use Tests\Support\TestSettings;
use Tests\Support\TestStore;

/*
| The store's launch checklist (docs/tenant-onboarding.md): computed from the store's real
| state — its own settings first, then each module's check. A failing check is "not done".
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

it('lists the store settings and every module check with its state', function (): void {
    app(OnboardingChecks::class)->register(ExplodingCheck::class);
    TestSettings::set('store.logo', null);

    $items = collect(app(OnboardingChecks::class)->items())->keyBy('label');

    expect($items->keys()->all())->toContain(
        'Storefront URL set', 'Logo uploaded', 'Store email sender set', 'Products on sale',
        'Delivery method', 'Payment method', 'Home page published', 'Test order placed',
    )
        ->and($items['Storefront URL set']['done'])->toBeTrue()       // seeded from the storefront address
        ->and($items['Logo uploaded']['done'])->toBeFalse()
        ->and($items['Delivery method']['done'])->toBeTrue()          // seeded with every store
        ->and($items['Home page published']['done'])->toBeTrue()      // seeded with every store
        ->and($items['Exploding check']['done'])->toBeFalse()
        ->and($items['Exploding check']['hint'])->toBe('Could not be checked: RuntimeException');
});

it('shows the checklist to an owner in the store admin', function (): void {
    $this->actingAs(TestStore::staff(role: 'Owner'), 'staff')
        ->get('http://localhost/settings/launch-checklist')
        ->assertOk()
        ->assertSee('Storefront URL set')
        ->assertSee('Products on sale');
});

it('keeps the checklist from staff without settings.manage', function (): void {
    $this->actingAs(TestStore::staff(role: 'Order Handler'), 'staff')
        ->get('http://localhost/settings/launch-checklist')
        ->assertForbidden();
});
