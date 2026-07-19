<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24J — GAP-021 (SRS-7-1 / SRS-7-12, SRS §7.19): every homework
 * assignment must reference a Lesson (booking) or a Learning Plan.
 *
 * Additive only:
 *  - New nullable learning_plan_id FK to student_learning_plans (bigint PK).
 *    restrictOnDelete: homework is an educational record and must never be
 *    orphaned or silently unlinked by a plan hard-delete (plans are archived
 *    via status + soft delete in normal operation; a hard delete with
 *    dependent homework is refused at the database).
 *  - Existing booking_id FK retightened from nullOnDelete to
 *    restrictOnDelete: SET NULL could silently strip a homework row's last
 *    context (and MySQL error 3823 refuses a CHECK over a SET NULL column
 *    for exactly that reason). Booking uses PreventsHardDeletion, so the
 *    restrict action never fires in normal operation — this only closes the
 *    door on a hard delete orphaning homework.
 *  - CHECK constraint enforcing booking_id IS NOT NULL OR
 *    learning_plan_id IS NOT NULL. A read-only preflight refuses to install
 *    the constraint over violating legacy rows so the migration fails loudly
 *    instead of half-applying; remediate those rows first, then re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hasColumn guard keeps the migration re-runnable if the preflight
        // below aborted a previous attempt after the column was added
        // (MySQL DDL is not transactional).
        if (! Schema::hasColumn('homework_assignments', 'learning_plan_id')) {
            Schema::table('homework_assignments', function (Blueprint $table): void {
                $table->dropForeign(['booking_id']);
                $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();

                $table->unsignedBigInteger('learning_plan_id')->nullable()->after('booking_id');
                $table->foreign('learning_plan_id')->references('id')->on('student_learning_plans')->restrictOnDelete();
                $table->index('learning_plan_id');
            });
        }

        $violations = DB::table('homework_assignments')
            ->whereNull('booking_id')
            ->whereNull('learning_plan_id')
            ->count();

        if ($violations > 0) {
            throw new RuntimeException(sprintf(
                'Cannot install homework context CHECK constraint: %d homework_assignments row(s) have neither '
                .'booking_id nor learning_plan_id. Remediate them through an approved deterministic mapping '
                .'(never guess relationships), then re-run this migration.',
                $violations,
            ));
        }

        DB::statement(
            'ALTER TABLE homework_assignments ADD CONSTRAINT chk_homework_assignments_context '
            .'CHECK (booking_id IS NOT NULL OR learning_plan_id IS NOT NULL)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE homework_assignments DROP CONSTRAINT chk_homework_assignments_context');

        Schema::table('homework_assignments', function (Blueprint $table): void {
            $table->dropForeign(['learning_plan_id']);
            $table->dropIndex(['learning_plan_id']);
            $table->dropColumn('learning_plan_id');

            $table->dropForeign(['booking_id']);
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
        });
    }
};
