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

    public static function group(): string
    {
        return 'lessons';
    }
}
