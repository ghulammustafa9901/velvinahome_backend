<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Actions\RegisterCustomer;
use Modules\Identity\Data\CustomerRegistrationData;
use Modules\Identity\Models\LoginAudit;
use Modules\Settings\Actions\UpdateSetting;
use Modules\Settings\Contracts\TenantSettings;
use Tests\Support\TestStore;

/*
| Spec §6.2: separate guards per population, login audit for staff, throttling and lockout,
| and authorisation enforced inside Actions — not only in the UI.
*/

it('audits staff sign-in successes and failures', function (): void {
    $staff = TestStore::staff('correct-horse-9');

    expect(Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'nope-nope-9']))->toBeFalse()
        ->and(Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'correct-horse-9']))->toBeTrue();

    $audits = LoginAudit::query()->where('email_attempted', $staff->email)->orderBy('id')->get();

    expect($audits->pluck('successful')->all())->toBe([false, true])
        ->and($audits->first()?->failure_reason)->toBe('invalid_password')
        ->and($audits->first()?->subject_type)->toBe('staff');
});

it('locks a staff account after repeated failures', function (): void {
    $staff = TestStore::staff('correct-horse-9');

    foreach (range(1, (int) config('platform.staff.max_login_attempts')) as $attempt) {
        Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'nope-nope-9']);
    }

    expect(fn () => Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'correct-horse-9']))
        ->toThrow(ValidationException::class);
});

it('keeps staff and customers on separate guards and tables', function (): void {
    $staff = TestStore::staff('correct-horse-9');
    $email = 'guard-'.uniqid().'@example.test';
    app(RegisterCustomer::class)->handle(CustomerRegistrationData::from([
        'email' => $email, 'password' => 'Sufficiently-long-9', 'first_name' => 'Sana', 'last_name' => 'Iqbal',
    ]));

    // A customer account means nothing to the staff guard, and staff do not exist as customers.
    expect(Auth::guard('staff')->validate(['email' => $email, 'password' => 'Sufficiently-long-9']))->toBeFalse()
        ->and(DB::table('customers')->where('email', $staff->email)->exists())->toBeFalse()
        ->and(Auth::guard('staff')->validate(['email' => $staff->email, 'password' => 'correct-horse-9']))->toBeTrue();
});

it('enforces permissions inside the Action even when the UI is bypassed', function (): void {
    $orderHandler = TestStore::staff(role: 'Order Handler');
    $owner = TestStore::staff(role: 'Owner');

    expect(fn () => app(UpdateSetting::class)->handle('store', 'name', 'Hijacked', actor: $orderHandler))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    $setting = app(UpdateSetting::class)->handle('store', 'contact_phone', '+92 300 0000000', actor: $owner);

    expect($setting->value)->toBe('+92 300 0000000');
});

it('stores encrypted settings as unreadable ciphertext', function (): void {
    app(UpdateSetting::class)->handle('integrations', 'probe_secret', 'sk_live_not_really', encrypted: true);

    $raw = DB::table('settings')->where('group', 'integrations')->where('key', 'probe_secret')->value('value');

    expect($raw)->not->toContain('sk_live_not_really')
        ->and(app(TenantSettings::class)->get('integrations.probe_secret'))->toBe('sk_live_not_really')
        ->and(app(TenantSettings::class)->group('integrations'))->not->toHaveKey('probe_secret');
});
