<?php

declare(strict_types=1);

use Modules\Support\Http\EnforceSessionAge;
use Tests\Support\TestPlatform;

/*
| ASVS 3.3.2: panel sessions end after a fixed number of hours, however active they are.
*/

beforeEach(function (): void {
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
    $this->staff = TestPlatform::staff($this->brand, role: 'Owner');
    // As loaded from the database (the factory-made instance lacks the column).
    $this->staff->setRememberToken(null);
    $this->admin = 'http://'.TestPlatform::adminHost(TestPlatform::BRAND_B);
});

it('stamps the start of an authenticated panel session', function (): void {
    $this->actingAs($this->staff, 'staff')->get($this->admin.'/products')
        ->assertOk()
        ->assertSessionHas(EnforceSessionAge::SESSION_KEY);
});

it('keeps a session younger than the limit', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->withSession([EnforceSessionAge::SESSION_KEY => now()->subHours(11)->getTimestamp()])
        ->get($this->admin.'/products')
        ->assertOk();
});

it('signs out a session older than the limit, even an active one', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->withSession([EnforceSessionAge::SESSION_KEY => now()->subHours(12)->subMinute()->getTimestamp()])
        ->get($this->admin.'/products')
        ->assertRedirect('/login');

    expect(auth('staff')->check())->toBeFalse();
});
