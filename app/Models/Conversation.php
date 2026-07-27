<?php

declare(strict_types=1);

namespace App\Models;

use App\Messaging\Enums\ConversationStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * SRS §17.28-§17.36 — one conversation per (student,
 * instructor, context) triple. Written exclusively by
 * MessagingService; `status` reflects MessagingRestriction state or
 * an explicit close, never a bare assignment from a controller.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'student_id',
        'instructor_id',
        'context_type',
        'context_id',
        'status',
        'opened_by',
        'last_message_at',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest('sent_at');
    }

    /** For admin oversight (§17.36 "View reported conversations"). */
    public function messageReports(): HasManyThrough
    {
        return $this->hasManyThrough(MessageReport::class, Message::class)->latest('message_reports.created_at');
    }

    public function isParticipant(User $user): bool
    {
        return $user->id === $this->student_id || $user->id === $this->instructor_id;
    }

    public function otherParticipant(User $user): ?User
    {
        if ($user->id === $this->student_id) {
            return $this->instructor;
        }

        if ($user->id === $this->instructor_id) {
            return $this->student;
        }

        return null;
    }

    /**
     * Display-only — never the authorization boundary. Restricted to
     * Booking or StudentLearningPlan (§17.30's narrower V1 context set).
     */
    public function context(): ?Model
    {
        return $this->context_type::query()->find($this->context_id);
    }
}
