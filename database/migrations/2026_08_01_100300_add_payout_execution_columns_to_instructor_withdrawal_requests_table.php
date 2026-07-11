<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16A — the execution segment's remaining columns.
 * processing_at/paid_at/failed_at already exist (Phase 15 schema,
 * reserved for this phase). `reversed_at`/`reversed_by` back the new
 * paid -> reversed transition; `recovered_*` back the explicit,
 * authorized failed -> approved recovery (never automatic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_withdrawal_requests', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('failed_at');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users');
            $table->timestamp('recovered_at')->nullable()->after('failure_reason');
            $table->foreignId('recovered_by')->nullable()->after('recovered_at')->constrained('users');
            $table->string('recovery_reason', 500)->nullable()->after('recovered_by');
        });
    }

    public function down(): void
    {
        Schema::table('instructor_withdrawal_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('recovered_by');
            $table->dropColumn(['reversed_at', 'recovered_at', 'recovery_reason']);
        });
    }
};
