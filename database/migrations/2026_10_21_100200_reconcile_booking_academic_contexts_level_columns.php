<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3.1 dev-DB reconciliation. `2026_10_20_100000_create_booking_academic_contexts_table.php`
     * was revised in place (its Phase 3 table was still uncommitted) to
     * replace `academic_level_grade` with the new
     * education_system_level_id/level_term/level_value/level_display/
     * normalized_grade columns — the correct shape for any fresh
     * install. This migration additionally, idempotently reconciles a
     * dev database where that migration had already run against the
     * OLD shape and `migrate:rollback` is unavailable in this
     * environment: every change below is guarded by a `hasColumn()`
     * check, so it is a safe no-op on a fresh install (where the table
     * was already created in its final shape) and a corrective ALTER
     * on an already-migrated dev database.
     */
    public function up(): void
    {
        Schema::table('booking_academic_contexts', function (Blueprint $table): void {
            if (Schema::hasColumn('booking_academic_contexts', 'academic_level_grade')) {
                $table->dropColumn('academic_level_grade');
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'education_system_level_id')) {
                $table->uuid('education_system_level_id')->nullable()->after('academic_level_name');
                $table->foreign('education_system_level_id')->references('id')->on('education_system_levels')->nullOnDelete();
                $table->index('education_system_level_id');
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'level_term')) {
                $table->string('level_term', 50)->nullable()->after('education_system_level_id');
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'level_value')) {
                $table->string('level_value', 50)->nullable()->after('level_term');
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'level_display')) {
                $table->string('level_display', 100)->nullable()->after('level_value');
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'normalized_grade')) {
                $table->unsignedTinyInteger('normalized_grade')->nullable()->after('level_display');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_academic_contexts', function (Blueprint $table): void {
            if (Schema::hasColumn('booking_academic_contexts', 'education_system_level_id')) {
                $table->dropForeign(['education_system_level_id']);
                $table->dropColumn(['education_system_level_id', 'level_term', 'level_value', 'level_display', 'normalized_grade']);
            }

            if (! Schema::hasColumn('booking_academic_contexts', 'academic_level_grade')) {
                $table->string('academic_level_grade', 50)->nullable()->after('academic_level_name');
            }
        });
    }
};
