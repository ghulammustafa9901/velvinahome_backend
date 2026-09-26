<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

// Test probe: always fails.
return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('Deliberate failure from the migration probe.');
    }
};
