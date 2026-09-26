<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\PlatformLoginAudit;
use Modules\Platform\Models\PlatformUser;
use Tests\Support\TestPlatform;

/*
| Spec §6.2: staff and platform users must enrol a second factor before reaching any panel
| page; platform sign-ins are audited.
*/

/**
 * Filament attaches the "set up MFA first" middleware when routes are registered, so the
 * requirement has to be on when the application boots — exactly as in every real
 * environment, where STAFF_2FA_REQUIRED is true.
 */
function bootWithMandatoryTwoFactor(object $test): void
{
    putenv('STAFF_2FA_REQUIRED=true');
    $_ENV['STAFF_2FA_REQUIRED'] = $_SERVER['STAFF_2FA_REQUIRED'] = 'true';
    $test->rebootApplication();
}

afterEach(function (): void {
    putenv('STAFF_2FA_REQUIRED=false');
    $_ENV['STAFF_2FA_REQUIRED'] = $_SERVER['STAFF_2FA_REQUIRED'] = 'false';
});

it('keeps staff without a confirmed second factor out of every admin page', function (): void {
    bootWithMandatoryTwoFactor($this);
    $staff = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Owner');

    expect($staff->two_factor_confirmed_at)->toBeNull();

    $response = $this->actingAs($staff, 'staff')->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/staff');

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('multi-factor-authentication');
});

it('keeps platform users without a second factor out of the platform panel', function (): void {
    bootWithMandatoryTwoFactor($this);

    $user = PlatformUser::query()->create([
        'name' => 'No 2FA', 'email' => 'no2fa-'.uniqid().'@platform.test', 'password' => 'platform-pass-99',
        'role' => PlatformRole::Engineer, 'status' => 'active',
    ]);

    $response = $this->actingAs($user, 'platform')->get('http://platform.test/tenants');

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('multi-factor-authentication');
});

it('audits platform sign-in attempts', function (): void {
    $email = 'audit-'.uniqid().'@platform.test';
    PlatformUser::query()->create([
        'name' => 'Audited', 'email' => $email, 'password' => 'platform-pass-99',
        'role' => PlatformRole::Engineer, 'status' => 'active',
    ]);

    Auth::guard('platform')->attempt(['email' => $email, 'password' => 'wrong-password-1']);
    Auth::guard('platform')->attempt(['email' => $email, 'password' => 'platform-pass-99']);

    expect(PlatformLoginAudit::query()->where('email_attempted', $email)->orderBy('id')->pluck('successful')->all())
        ->toBe([false, true]);
});
