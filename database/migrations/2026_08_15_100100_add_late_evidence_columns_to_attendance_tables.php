<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17B: evidence arriving after the attendance record is sealed
 * (or after the lesson left its open state) is still stored for audit —
 * flagged is_late and excluded from the merged aggregates — and the
 * record carries late_evidence_reported_at, which acts as an
 * administrative hold: the automated finalizer skips flagged lessons
 * so a human decides instead of the evidence silently rewriting a
 * finalized outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_attendance_events', function (Blueprint $table): void {
            $table->boolean('is_late')->default(false)->after('attended_seconds');
        });

        Schema::table('lesson_attendance_records', function (Blueprint $table): void {
            $table->timestamp('late_evidence_reported_at')->nullable()->after('technical_issue_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_attendance_events', function (Blueprint $table): void {
            $table->dropColumn('is_late');
        });

        Schema::table('lesson_attendance_records', function (Blueprint $table): void {
            $table->dropColumn('late_evidence_reported_at');
        });
    }
};
