<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\LoginAudit;
use Modules\Platform\Models\PlatformUser;
use Modules\Settings\Actions\UpdateSetting;
use Tests\Support\TestPlatform;

/*
| Spec §6.2: separate guards per population, login audit for staff, throttling and lockout,
| and authorisation enforced inside Actions — not only in the UI.
*/

it('audits staff sign-in successes and failures', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_B);
    $staff = TestPlatform::staff($tenant, 'correct-horse-9');

    $tenant->run(function () use ($staff): void {
        expect(Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'nope-nope-9']))->toBeFalse()
            ->and(Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'correct-horse-9']))->toBeTrue();

        $audits = LoginAudit::query()->where('email_attempted', $staff->email)->orderBy('id')->get();

        expect($audits->pluck('successful')->all())->toBe([false, true])
            ->and($audits->first()?->failure_reason)->toBe('invalid_password')
            ->and($audits->first()?->subject_type)->toBe('staff');
    });
});

it('locks a staff account after repeated failures', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_B);
    $staff = TestPlatform::staff($tenant, 'correct-horse-9');

    $tenant->run(function () use ($staff): void {
        foreach (range(1, (int) config('platform.staff.max_login_attempts')) as $attempt) {
            Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'nope-nope-9']);
        }

        expect(fn () => Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => 'correct-horse-9']))
            ->toThrow(ValidationException::class);
    });
});

it('keeps the three user populations on separate guards', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_B);
    $staff = TestPlatform::staff($tenant, 'correct-horse-9');

    $platformUser = PlatformUser::query()->create([
        'name' => 'Engineer', 'email' => 'eng-'.uniqid().'@platform.test', 'password' => 'platform-pass-99',
        'role' => 'engineer', 'status' => 'active',
    ]);

    $tenant->run(function () use ($staff, $platformUser): void {
        // Staff credentials mean nothing to the platform guard, and a platform user does not
        // exist for the tenant staff guard.
        expect(Auth::guard('platform')->validate(['email' => $staff->email, 'password' => 'correct-horse-9']))->toBeFalse()
            ->and(Auth::guard('staff')->validate(['email' => $platformUser->email, 'password' => 'platform-pass-99']))->toBeFalse();
    });

    expect(Auth::guard('platform')->validate(['email' => $platformUser->email, 'password' => 'platform-pass-99']))->toBeTrue();
});

it('enforces permissions inside the Action even when the UI is bypassed', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_B);
    $orderHandler = TestPlatform::staff($tenant, role: 'Order Handler');
    $owner = TestPlatform::staff($tenant, role: 'Owner');

    $tenant->run(function () use ($orderHandler, $owner): void {
        expect(fn () => app(UpdateSetting::class)->handle('store', 'name', 'Hijacked', actor: $orderHandler))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

        $setting = app(UpdateSetting::class)->handle('store', 'contact_phone', '+92 300 0000000', actor: $owner);

        expect($setting->value)->toBe('+92 300 0000000');
    });
});

it('stores encrypted settings as unreadable ciphertext', function (): void {
    $tenant = TestPlatform::brand(TestPlatform::BRAND_B);

    $tenant->run(function (): void {
        app(UpdateSetting::class)->handle('integrations', 'probe_secret', 'sk_live_not_really', encrypted: true);

        $raw = Illuminate\Support\Facades\DB::table('settings')->where('group', 'integrations')->where('key', 'probe_secret')->value('value');

        expect($raw)->not->toContain('sk_live_not_really')
            ->and(app(Modules\Settings\Contracts\TenantSettings::class)->get('integrations.probe_secret'))->toBe('sk_live_not_really')
            ->and(app(Modules\Settings\Contracts\TenantSettings::class)->group('integrations'))->not->toHaveKey('probe_secret');
    });
});
