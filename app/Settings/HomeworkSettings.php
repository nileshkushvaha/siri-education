<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class HomeworkSettings extends Settings
{
    /** Master switch — false stops the scheduler from claiming or sending any due-date reminder. */
    public bool $homework_due_reminders_enabled;

    /**
     * Hours before due_at at which a reminder is claimed, e.g. [24] =
     * one reminder 24 hours before the due time. Validated and
     * normalized (sorted, deduplicated, bounded) by the settings page
     * before save — use normalizedOffsets() rather than this raw
     * property when reading for dispatch.
     *
     * @var list<int>
     */
    public array $homework_due_reminder_offset_hours;

    /**
     * Channel routing for reminder notifications, mirroring
     * ReviewSettings' channel toggles. In-app (database) delivery is
     * always on for real users; these gate only external channels.
     */
    public bool $homework_reminder_channel_email_enabled;

    public bool $homework_reminder_channel_whatsapp_enabled;

    public bool $homework_reminder_channel_sms_enabled;

    public static function group(): string
    {
        return 'homework';
    }

    /**
     * Sorted, deduplicated, positive-only view of the configured
     * offsets — the only form the dispatcher/command should consume.
     *
     * @return list<int>
     */
    public function normalizedOffsets(): array
    {
        $offsets = array_values(array_unique(array_filter(
            $this->homework_due_reminder_offset_hours,
            fn (mixed $hours): bool => is_int($hours) && $hours > 0,
        )));

        sort($offsets);

        return $offsets;
    }
}
