<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Identity\Actions\CreateStaffMember;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Filament\Resources\Staff\Pages\ListStaffPage;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\PlatformUser;
use Tests\Support\TestPlatform;

/*
| Plan 1.6: the admin panel works on the resolved tenant only; only platform users reach the
| landlord panel, and tenant staff get 404 (not 403) on the platform host.
*/

function platformUser(PlatformRole $role = PlatformRole::Engineer): PlatformUser
{
    return PlatformUser::query()->create([
        'name' => 'Panel '.$role->value, 'email' => $role->value.'-'.uniqid().'@platform.test', 'password' => 'platform-pass-99',
        'role' => $role, 'status' => 'active',
    ]);
}

it('shows platform users every brand in the platform panel', function (): void {
    $this->actingAs(platformUser(), 'platform')
        ->get('http://platform.test/tenants')
        ->assertOk()
        ->assertSee('Brand FIX-A')
        ->assertSee('Brand FIX-B')
        ->assertSee('Brand FIX-C');

    $this->get('http://platform.test/companies')->assertOk()->assertSee('Fixture Y');
});

it('redirects guests to the platform login', function (): void {
    $this->get('http://platform.test/tenants')->assertRedirect('http://platform.test/login');
});

it('does not expose platform panel pages on a tenant admin host', function (): void {
    $staff = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Owner');

    // The tenant admin has no "tenants" resource; the platform one does not exist here.
    $this->actingAs($staff, 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/tenants')
        ->assertNotFound();
});

it('lists only the current brand\'s staff in the tenant admin', function (): void {
    $viewerB = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Owner');
    $inC = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_C), role: 'Manager');

    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/login')->assertOk();

    tenancy()->initialize(TestPlatform::brand(TestPlatform::BRAND_B));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($viewerB, 'staff');

    $ownStaff = StaffUser::query()->get();

    Livewire::test(ListStaffPage::class)
        ->loadTable()
        ->assertCountTableRecords($ownStaff->count())
        ->searchTable($viewerB->email)
        ->assertCanSeeTableRecords($ownStaff->where('id', $viewerB->id))
        ->searchTable($inC->email)
        ->assertCountTableRecords(0);

    // Brand C's staff member lives in another database: nothing with that email exists here.
    expect(StaffUser::query()->where('email', $inC->email)->exists())->toBeFalse();
});

it('hides staff management from roles without the permission', function (): void {
    $orderHandler = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Order Handler');

    $this->actingAs($orderHandler, 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/staff')
        ->assertForbidden();
});

it('lets only an owner add another owner, checked inside the Action', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    $manager = TestPlatform::staff($brandB, role: 'Manager');

    $brandB->run(function () use ($manager): void {
        $data = StaffMemberData::from(['name' => 'Would-be owner', 'email' => 'wbo-'.uniqid().'@example.test', 'role' => StaffRole::Owner]);

        expect(fn () => app(CreateStaffMember::class)->handle($data, $manager))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    });
});
