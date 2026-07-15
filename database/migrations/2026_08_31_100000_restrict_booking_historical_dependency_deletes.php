<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17U.1 — remediates Phase 17T Finding S-2. Every foreign key
 * that previously cascade-deleted a historical booking/lesson/review/
 * financial/feedback/quality record now RESTRICTs instead — deleting
 * a `users`/`bookings`/`lessons`/`lesson_review_eligibilities`/
 * `lesson_reviews` row that still has any of these dependents is
 * rejected by the database itself, not merely by application code.
 * Constraint names below were read from live `information_schema`
 * metadata (all follow Laravel's `{table}_{column}_foreign`
 * convention already, since every one was originally created via
 * `->constrained()`/`->foreign()`), not assumed from migration
 * source. No table is dropped or recreated; only the `ON DELETE`
 * rule of each existing constraint changes — no data is touched.
 *
 * Deliberately NOT touched (out of the "historical business record"
 * scope this remediation targets): `homework_assignments.booking_id`
 * (already `SET NULL`, a different domain, out of this phase's
 * scope), and every FK that was already `RESTRICT`/`NO ACTION`/
 * `SET NULL` (already safe). `booking_guests` no longer exists
 * (Phase 17U.3 — authenticated-only booking, guest participants
 * removed) and `booking_meetings.booking_id` now ships `RESTRICT`
 * from its own baseline migration, so neither needs an entry here
 * anymore.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string, references: string}> */
    private array $constraints = [
        // users -> bookings (Phase 17U.1 §11 — account-deletion safety) is
        // handled directly in the baseline `create_bookings_table`
        // migration as of Phase 17U.3's `student_id`/`instructor_id`
        // rename — both already ship `restrictOnDelete()` from creation,
        // so there is nothing left for this migration to change here.

        // bookings -> historical dependents
        ['table' => 'booking_activities', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'booking_payments', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'instructor_student_feedback', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'lesson_attendance_records', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'lesson_financial_dispositions', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'lesson_review_eligibilities', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'lesson_reviews', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'lessons', 'column' => 'booking_id', 'references' => 'bookings'],

        // lessons -> historical dependents
        ['table' => 'instructor_earnings', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'instructor_student_feedback', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_attendance_confirmations', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_attendance_events', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_attendance_records', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_financial_dispositions', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_review_eligibilities', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_reviews', 'column' => 'lesson_id', 'references' => 'lessons'],
        ['table' => 'lesson_technical_issue_reports', 'column' => 'lesson_id', 'references' => 'lessons'],

        // second-order: review eligibility / review -> their own children
        ['table' => 'lesson_reviews', 'column' => 'eligibility_id', 'references' => 'lesson_review_eligibilities'],
        ['table' => 'lesson_review_revisions', 'column' => 'lesson_review_id', 'references' => 'lesson_reviews'],
        ['table' => 'review_rating_contributions', 'column' => 'review_id', 'references' => 'lesson_reviews'],
        ['table' => 'review_reports', 'column' => 'review_id', 'references' => 'lesson_reviews'],
    ];

    public function up(): void
    {
        foreach ($this->constraints as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk): void {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references('id')->on($fk['references'])
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->constraints) as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk): void {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references('id')->on($fk['references'])
                    ->cascadeOnDelete();
            });
        }
    }
};
