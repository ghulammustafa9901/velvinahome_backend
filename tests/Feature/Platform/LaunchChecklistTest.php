<?php

declare(strict_types=1);

use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\PlatformUser;
use Modules\Platform\Support\LaunchChecklist;
use Tests\Support\TestPlatform;

/*
| The platform panel's view of a brand's checklist: landlord facts plus the store's own
| checklist, run inside the brand. Removed with the platform panel (single-store plan R4).
*/

it('combines landlord facts with the store checklist, run inside the brand', function (): void {
    $items = collect(app(LaunchChecklist::class)->for(TestPlatform::brand(TestPlatform::BRAND_C)))->keyBy('label');

    expect($items['Storefront, admin and API addresses']['done'])->toBeTrue()
        ->and($items->keys()->all())->toContain('Plan assigned', 'Storefront URL set', 'Products on sale')
        ->and(tenant())->toBeNull();
});

it('shows the checklist to platform staff on the brands screen', function (): void {
    $user = PlatformUser::query()->create(['name' => 'Checklist viewer', 'email' => 'checklist-'.uniqid().'@platform.test', 'password' => 'platform-pass-99', 'role' => PlatformRole::Engineer, 'status' => 'active']);

    $this->actingAs($user, 'platform')
        ->get('http://platform.test/tenants')
        ->assertOk()
        ->assertSee('Launch checklist');
});
