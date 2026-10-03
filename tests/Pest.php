<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the application against real PostgreSQL, Redis and Typesense.
| Architecture and Unit tests do not need it: they inspect source code and exercise pure
| value objects, so keeping them application-free makes them fast and makes a failure
| unambiguous.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Groups
|--------------------------------------------------------------------------
|
| Architecture tests run as their own required CI check (spec §12.2), so a boundary
| violation is never mistaken for an ordinary test failure.
|
*/

pest()->group('architecture')->in('Architecture');
