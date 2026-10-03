<?php

declare(strict_types=1);

use Tests\Support\TestStore;

/*
| Spec §6.2: staff must enrol a second factor before reaching any admin page.
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
    $staff = TestStore::staff(role: 'Owner');

    expect($staff->two_factor_confirmed_at)->toBeNull();

    $response = $this->actingAs($staff, 'staff')->get('http://localhost/staff');

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('multi-factor-authentication');
});
