<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Http\Middleware\ScopeSessionToTenant;
use Spatie\Permission\Models\Role;
use Tests\Support\TestPlatform;

/*
| Isolation channels 3 (admin), 9 (sibling brands) and 10 (topology).
*/

it('does not accept one brand\'s staff credentials on a sibling brand', function (): void {
    $staff = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), 'correct-horse-9');
    $credentials = ['email' => $staff->email, 'password' => 'correct-horse-9'];

    expect(TestPlatform::brand(TestPlatform::BRAND_B)->run(fn (): bool => Auth::guard('staff')->validate($credentials)))->toBeTrue()
        ->and(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn (): bool => Auth::guard('staff')->validate($credentials)))->toBeFalse();
});

it('refuses a session created on one brand\'s admin when replayed on a sibling brand', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);

    $this->withSession([ScopeSessionToTenant::KEY => 'tenant:'.$brandB->id])
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).'/login')
        ->assertForbidden();

    $this->withSession([ScopeSessionToTenant::KEY => 'tenant:'.$brandB->id])
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/login')
        ->assertOk();
});

it('serves the tenant admin only on admin hosts and the platform panel only on the platform host', function (string $host, int $status): void {
    $this->get("http://{$host}/login")->assertStatus($status);
})->with([
    'tenant admin host' => [TestPlatform::adminHost(TestPlatform::BRAND_B), 200],
    'platform host' => ['platform.test', 200],
    'tenant API host' => [TestPlatform::apiHost(TestPlatform::BRAND_B), 404],
    'tenant storefront host' => [TestPlatform::BRAND_B.'.test', 404],
    'unknown host' => ['unknown.test', 404],
]);

it('refuses a tenant admin session on the platform host and vice versa', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);

    $this->withSession([ScopeSessionToTenant::KEY => 'tenant:'.$brandB->id])
        ->get('http://platform.test/login')
        ->assertForbidden();

    session()->flush();

    $this->withSession([ScopeSessionToTenant::KEY => 'platform'])
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/login')
        ->assertForbidden();
});

it('keeps permission caches per brand', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    $brandC = TestPlatform::brand(TestPlatform::BRAND_C);
    $staffB = TestPlatform::staff($brandB);
    $staffC = TestPlatform::staff($brandC);

    // Warm brand B's permission cache, then change brand C's Manager role.
    expect($brandB->run(fn (): bool => StaffUser::query()->findOrFail($staffB->id)->can('reviews.moderate')))->toBeTrue();

    $brandC->run(fn () => Role::findByName('Manager', 'staff')->revokePermissionTo('reviews.moderate'));

    try {
        expect($brandC->run(fn (): bool => StaffUser::query()->findOrFail($staffC->id)->can('reviews.moderate')))->toBeFalse()
            ->and($brandB->run(fn (): bool => StaffUser::query()->findOrFail($staffB->id)->can('reviews.moderate')))->toBeTrue();
    } finally {
        $brandC->run(fn () => Role::findByName('Manager', 'staff')->givePermissionTo('reviews.moderate'));
    }
});

it('serves only the pinned brand in the dedicated topology', function (): void {
    config([
        'platform.topology' => 'dedicated',
        'platform.pinned_tenant' => TestPlatform::brand(TestPlatform::BRAND_B)->id,
    ]);

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config')
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Brand FIX-B');

    $this->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/storefront/config')->assertNotFound();
    $this->getJson('http://unknown.test/api/v1/storefront/config')->assertNotFound();
});
