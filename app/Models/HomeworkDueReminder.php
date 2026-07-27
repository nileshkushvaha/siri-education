<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkReminderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only claim/operation ledger for
 * homework due-date reminders. Never hard-deleted or backdated; a
 * changed due date produces a new row via a new due_at_snapshot,
 * never a rewrite of an existing one.
 */
class HomeworkDueReminder extends Model
{
    protected $fillable = [
        'homework_assignment_id',
        'recipient_user_id',
        'due_at_snapshot',
        'reminder_offset_minutes',
        'status',
        'failure_category',
        'attempts',
        'claimed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at_snapshot' => 'datetime',
            'status' => HomeworkReminderStatus::class,
            'attempts' => 'integer',
            'reminder_offset_minutes' => 'integer',
            'claimed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_assignment_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** Per-channel delivery state (idempotency + audit). */
    public function channelDeliveries(): HasMany
    {
        return $this->hasMany(HomeworkReminderChannelDelivery::class, 'homework_due_reminder_id');
    }
}
