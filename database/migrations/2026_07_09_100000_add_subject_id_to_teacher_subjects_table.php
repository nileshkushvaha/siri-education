<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation step: teacher_subjects.subject stays a
 * free-text column — booking flows (AssignmentCriteriaData, GuestBookingData,
 * StudentBookingData, TeacherCandidateRepository) keep reading it exactly as
 * before, nothing here changes that. This only adds an optional link to the
 * Subject master so future-facing code (instructor onboarding, admin display)
 * can prefer structured data where it exists.
 *
 * Backfill strategy (case-insensitive exact match only, per the approved
 * reconciliation plan — no fuzzy matching):
 *   - For each teacher_subjects row, look for a subjects row whose name
 *     matches (case-insensitively) the free-text `subject` value.
 *   - Match found  -> set subject_id.
 *   - No match     -> leave subject_id null; the row keeps working exactly
 *     as it did before (free-text display/matching), and the unmapped count
 *     is written to the log for follow-up, not silently dropped.
 * Archived/inactive subjects are still eligible matches — an existing
 * teacher-subject link describes a fact about the teacher, not a new
 * assignment, so a later archival of the subject shouldn't sever it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_subjects', function (Blueprint $table): void {
            $table->uuid('subject_id')->nullable()->after('subject');
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->index('subject_id');
        });

        $matched = DB::update('
            UPDATE teacher_subjects
            INNER JOIN subjects ON LOWER(subjects.name) = LOWER(teacher_subjects.subject)
            SET teacher_subjects.subject_id = subjects.id
            WHERE teacher_subjects.subject_id IS NULL
        ');

        $unmapped = DB::table('teacher_subjects')->whereNull('subject_id')->count();

        Log::info('teacher_subjects.subject_id backfill complete.', [
            'matched_count' => $matched,
            'unmapped_count' => $unmapped,
            'unmapped_subject_values' => $unmapped > 0
                ? DB::table('teacher_subjects')->whereNull('subject_id')->distinct()->pluck('subject')->values()->all()
                : [],
        ]);
    }

    public function down(): void
    {
        Schema::table('teacher_subjects', function (Blueprint $table): void {
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');
        });
    }
};
