<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalized lesson outcome — stored separately from booking status and
 * from the operational LessonStatus. FinalizeLessonOutcomeAction /
 * OverrideLessonOutcomeAction are the only writers; outcome_version is
 * the concurrency lock value, incremented on every finalization and
 * override. Already-finalized lessons are backfilled so the
 * "terminal status implies finalized outcome" invariant holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('outcome', 32)->default('pending')->after('status');
            $table->string('outcome_reason_code', 64)->nullable()->after('outcome');
            $table->string('outcome_notes', 1000)->nullable()->after('outcome_reason_code');
            $table->timestamp('outcome_finalized_at')->nullable()->after('outcome_notes');
            $table->string('outcome_finalized_by_type', 16)->nullable()->after('outcome_finalized_at');
            $table->foreignId('outcome_finalized_by')->nullable()->after('outcome_finalized_by_type')->constrained('users')->nullOnDelete();
            $table->foreignUuid('attendance_record_id')->nullable()->after('outcome_finalized_by')->constrained('lesson_attendance_records')->nullOnDelete();
            $table->unsignedInteger('outcome_version')->default(0)->after('attendance_record_id');

            $table->index(['outcome', 'outcome_finalized_at']);
        });

        // Backfill lessons finalized before the outcome layer existed.
        foreach ([
            'completed' => 'completed',
            'student_no_show' => 'student_no_show',
            'instructor_no_show' => 'instructor_no_show',
            'both_no_show' => 'both_absent',
            'cancelled' => 'cancelled',
        ] as $status => $outcome) {
            DB::table('lessons')
                ->where('status', $status)
                ->where('outcome', 'pending')
                ->update([
                    'outcome' => $outcome,
                    'outcome_reason_code' => 'backfill_phase_17a',
                    'outcome_finalized_at' => DB::raw('COALESCE(completed_at, updated_at)'),
                    'outcome_finalized_by_type' => 'system',
                    'outcome_version' => 1,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex(['outcome', 'outcome_finalized_at']);
            $table->dropConstrainedForeignId('outcome_finalized_by');
            $table->dropConstrainedForeignId('attendance_record_id');
            $table->dropColumn([
                'outcome',
                'outcome_reason_code',
                'outcome_notes',
                'outcome_finalized_at',
                'outcome_finalized_by_type',
                'outcome_version',
            ]);
        });
    }
};
