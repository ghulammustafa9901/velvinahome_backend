<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test probe: succeeds, so the test can prove it is rolled back with its failing sibling.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_first', function (Blueprint $table): void {
            $table->id();
        });
    }
};
