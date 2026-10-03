<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Identity\Actions\CreateStaffMember;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Enums\StaffRole;
use Modules\Identity\Filament\Resources\Staff\Pages\ListStaffPage;
use Modules\Identity\Models\StaffUser;
use Tests\Support\TestStore;

/*
| The store admin: staff management follows permissions, owners are protected inside the
| Action, and operations tooling (Horizon, API docs) needs system.monitor.
*/

it('lists the store\'s staff in the admin', function (): void {
    $owner = TestStore::staff(role: 'Owner');

    $this->get('http://localhost/login')->assertOk();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($owner, 'staff');

    Livewire::test(ListStaffPage::class)
        ->loadTable()
        ->assertCountTableRecords(StaffUser::query()->count())
        ->searchTable($owner->email)
        ->assertCanSeeTableRecords(StaffUser::query()->whereKey($owner->id)->get());
});

it('hides staff management from roles without the permission', function (): void {
    $this->actingAs(TestStore::staff(role: 'Order Handler'), 'staff')
        ->get('http://localhost/staff')
        ->assertForbidden();
});

it('lets only an owner add another owner, checked inside the Action', function (): void {
    $manager = TestStore::staff(role: 'Manager');
    $data = StaffMemberData::from(['name' => 'Would-be owner', 'email' => 'wbo-'.uniqid().'@example.test', 'role' => StaffRole::Owner]);

    expect(fn () => app(CreateStaffMember::class)->handle($data, $manager))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('opens Horizon to staff with system.monitor only', function (): void {
    $this->get('http://localhost/horizon')->assertRedirect('http://localhost/login');

    $this->actingAs(TestStore::staff(role: 'Manager'), 'staff')->get('http://localhost/horizon')->assertForbidden();
});

it('opens Horizon to an owner', function (): void {
    $this->actingAs(TestStore::staff(role: 'Owner'), 'staff')->get('http://localhost/horizon')->assertOk();
});
