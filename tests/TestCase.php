<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestPlatform;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestPlatform::prepare();
    }

    protected function tearDown(): void
    {
        // A test that fails mid-way must not leave the next one inside a tenant.
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }
}
