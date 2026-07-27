<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LessonSettings extends Settings
{
    /** Platform-wide switch for the lessons:auto-complete sweep. */
    public bool $auto_complete_enabled;

    /** Minutes after ends_at before an open lesson is auto-finalized. */
    public int $auto_complete_grace_minutes;

    /** Minutes after starts_at before a no-show may be recorded (admin override bypasses). */
    public int $no_show_grace_minutes;

    /** Completion requires instructor attendance = attended (admin override bypasses; also gates the auto sweep). */
    public bool $require_instructor_completion;

    /** Completion requires student attendance = attended (admin override bypasses; also gates the auto sweep). */
    public bool $require_student_attendance;

    /** Merged seconds a party must attend to qualify (0 = any recorded join qualifies — disabled-safe default). */
    public int $min_attendance_seconds;

    /**
     * Master switch for the evidence-driven finalization engine
     * (lessons:finalize-due). Ships OFF: while off, nothing changes and the
     * legacy lenient sweep (lessons:auto-complete) keeps running; while on,
     * the legacy sweep defers so exactly one automation policy is active.
     */
    public bool $automated_finalization_enabled;

    /** Minutes after ends_at before the attendance record is sealed — the window in which provider evidence may still arrive. */
    public int $attendance_finalize_delay_minutes;

    /** Minutes after ends_at before a StudentNoShow outcome may be auto-finalized. */
    public int $student_no_show_grace_minutes;

    /** Minutes after ends_at before an InstructorNoShow outcome may be auto-finalized. */
    public int $instructor_no_show_grace_minutes;

    /**
     * Minutes after ends_at during which a reported technical issue keeps
     * every automated outcome on hold; once closed, the outcome finalizes
     * as TechnicalIssue (status Disputed) for a human decision.
     * Auto-completion delay reuses auto_complete_grace_minutes.
     */
    public int $technical_issue_window_minutes;

    /** Lessons per processing chunk in lessons:finalize-due (memory/batch determinism). */
    public int $finalize_batch_size;

    public static function group(): string
    {
        return 'lessons';
    }
}
