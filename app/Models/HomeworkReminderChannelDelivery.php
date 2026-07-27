<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkReminderChannelStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable per-channel delivery state for one
 * homework due-date reminder. Never rewritten to represent a different
 * channel or reminder — one row per (reminder, channel) for the life
 * of the reminder.
 */
class HomeworkReminderChannelDelivery extends Model
{
    protected $fillable = [
        'homework_due_reminder_id',
        'channel',
        'status',
        'attempts',
        'failure_category',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HomeworkReminderChannelStatus::class,
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(HomeworkDueReminder::class, 'homework_due_reminder_id');
    }
}
