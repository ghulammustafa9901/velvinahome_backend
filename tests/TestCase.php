<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestStore;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestStore::prepare();
    }

    /**
     * Re-create the application, for settings that take effect only at boot (for example
     * Filament's route-level middleware).
     */
    public function rebootApplication(): void
    {
        $this->refreshApplication();
    }
}
