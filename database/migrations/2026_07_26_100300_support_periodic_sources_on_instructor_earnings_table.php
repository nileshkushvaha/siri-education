<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.2 — instructor_earnings stays the single canonical
 * settleable/withdrawable source (Phase 15 reservations keep working
 * unchanged), but must now also carry periodic compensation that has no
 * lesson or booking:
 *
 * - lesson_id / booking_id become nullable. The lesson unique index
 *   survives (MySQL unique indexes allow unlimited NULLs), so
 *   one-earning-per-lesson stays DB-enforced for lesson sources.
 * - (source_type, source_id) becomes unique: one earning per canonical
 *   source row of any category — lesson, compensation period, or a
 *   future incentive/adjustment source. Existing rows satisfy this by
 *   construction (source_id was the unique lesson_id).
 *
 * No historical row is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->foreignUuid('lesson_id')->nullable()->change();
            $table->foreignUuid('booking_id')->nullable()->change();
            $table->unique(['source_type', 'source_id'], 'ie_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->dropUnique('ie_source_unique');
        });
    }
};
