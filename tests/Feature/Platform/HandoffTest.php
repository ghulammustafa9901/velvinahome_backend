<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\StaffUser;
use Modules\Platform\Actions\IssueBrandSwitch;
use Modules\Platform\Actions\StartImpersonation;
use Modules\Platform\Enums\PlatformRole;
use Modules\Platform\Models\CompanyOwnerBrandLink;
use Modules\Platform\Models\ImpersonationSession;
use Modules\Platform\Models\PlatformActivity;
use Modules\Platform\Models\PlatformUser;
use Tests\Support\TestPlatform;

/*
| Plan 1.6: impersonation needs a reason, is single-use, time-limited, scoped to the chosen
| tenant and audited on both sides; the brand switcher only moves a company owner between
| their own company's brands, through single-use tokens.
*/

function platformEngineer(): PlatformUser
{
    return PlatformUser::query()->create([
        'name' => 'Engineer', 'email' => 'eng-'.uniqid().'@platform.test', 'password' => 'platform-pass-99',
        'role' => PlatformRole::Engineer, 'status' => 'active',
    ]);
}

function pathOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

function ownerLink(string $slug): CompanyOwnerBrandLink
{
    return CompanyOwnerBrandLink::query()->where('tenant_id', TestPlatform::brand($slug)->id)->firstOrFail();
}

it('requires a meaningful reason for support access', function (): void {
    app(StartImpersonation::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), platformEngineer(), 'because');
})->throws(ValidationException::class);

it('signs support into the chosen brand once, and audits it on both sides', function (): void {
    $brandB = TestPlatform::brand(TestPlatform::BRAND_B);
    $grant = app(StartImpersonation::class)->handle($brandB, platformEngineer(), 'Customer reported a checkout bug #123');
    $adminB = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_B);

    $this->get($adminB.pathOf($grant['url']))->assertRedirect('/');

    $signedIn = auth('staff')->user();
    expect($signedIn)->toBeInstanceOf(StaffUser::class)
        ->and($signedIn?->ulid)->toBe($grant['session']->target_staff_user_ulid);

    // Single use.
    session()->flush();
    auth('staff')->logout();
    $this->get($adminB.pathOf($grant['url']))->assertNotFound();

    expect(PlatformActivity::query()->where('event', 'tenant.impersonation_started')->where('subject_id', $grant['session']->ulid)->exists())->toBeTrue()
        ->and($brandB->run(fn (): bool => DB::table('activity_log')->where('event', 'platform_support_access')
            ->where('properties->impersonation_session', $grant['session']->ulid)->exists()))->toBeTrue();
});

it('refuses the support token on any other brand, including a sibling', function (): void {
    $grant = app(StartImpersonation::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), platformEngineer(), 'Checking a sibling-brand leak attempt');

    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).pathOf($grant['url']))->assertNotFound();

    // The failed attempt did not burn the token for its real brand.
    session()->flush();
    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).pathOf($grant['url']))->assertRedirect('/');
});

it('refuses an expired support token and ends an expired support session', function (): void {
    $grant = app(StartImpersonation::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), platformEngineer(), 'Verifying expiry of support sessions');
    $adminB = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_B);

    $this->get($adminB.pathOf($grant['url']))->assertRedirect('/');

    $this->travel((int) config('platform.impersonation.ttl_minutes') + 1)->minutes();

    $this->get($adminB.'/')->assertRedirect('/login');

    expect(ImpersonationSession::query()->where('ulid', $grant['session']->ulid)->value('ended_at'))->not->toBeNull();
});

it('moves a company owner between their own brands with a single-use token', function (): void {
    $fromB = ownerLink(TestPlatform::BRAND_B);
    $toC = ownerLink(TestPlatform::BRAND_C);

    expect($fromB->company_owner_identity_id)->toBe($toC->company_owner_identity_id);

    $url = app(IssueBrandSwitch::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), $fromB->tenant_staff_user_ulid, TestPlatform::brand(TestPlatform::BRAND_C));
    $adminC = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_C);

    expect($url)->toStartWith($adminC.'/_handoff/brand/');

    $this->get($adminC.pathOf($url))->assertRedirect('/');
    expect(auth('staff')->user()?->ulid)->toBe($toC->tenant_staff_user_ulid);

    session()->flush();
    auth('staff')->logout();
    $this->get($adminC.pathOf($url))->assertNotFound();
});

it('expires brand switch tokens quickly', function (): void {
    $fromB = ownerLink(TestPlatform::BRAND_B);
    $url = app(IssueBrandSwitch::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), $fromB->tenant_staff_user_ulid, TestPlatform::brand(TestPlatform::BRAND_C));

    $this->travel((int) config('platform.brand_switch.ttl_seconds') + 1)->seconds();

    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).pathOf($url))->assertNotFound();
});

it('denies switching into a brand of another company', function (): void {
    $ownerOfA = ownerLink(TestPlatform::BRAND_A);

    app(IssueBrandSwitch::class)->handle(TestPlatform::brand(TestPlatform::BRAND_A), $ownerOfA->tenant_staff_user_ulid, TestPlatform::brand(TestPlatform::BRAND_B));
})->throws(AuthorizationException::class);

it('denies the switcher to staff who are not the linked owner', function (): void {
    $staff = TestPlatform::staff(TestPlatform::brand(TestPlatform::BRAND_B), role: 'Owner');

    app(IssueBrandSwitch::class)->handle(TestPlatform::brand(TestPlatform::BRAND_B), $staff->ulid, TestPlatform::brand(TestPlatform::BRAND_C));
})->throws(AuthorizationException::class);

it('answers 404 to a forged hand-off token', function (): void {
    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).'/_handoff/brand/'.str_repeat('ab', 32))->assertNotFound();
    $this->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_C).'/_handoff/support/'.str_repeat('cd', 32))->assertNotFound();
});
