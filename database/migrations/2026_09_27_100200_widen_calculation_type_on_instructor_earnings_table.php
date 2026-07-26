<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `calculation_type` was `string(16)`, wide enough for every
 * pre-existing value ('hourly', 'periodic', 'demo_fixed', 'manual') but
 * too narrow for the new 'demo_conversion_incentive' case (25 chars).
 * Widened to match `source_type`'s existing width (32) rather than an
 * exact fit, so a future addition doesn't need another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->string('calculation_type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->string('calculation_type', 16)->change();
        });
    }
};
