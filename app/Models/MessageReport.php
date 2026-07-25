<?php

declare(strict_types=1);

namespace App\Models;

use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Enums\MessageReportStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\MessageReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report per reporter per message (SRS §17.35). Written
 * exclusively by MessagingService::reportMessage() (creation) and
 * ::reviewReport() (status/review fields).
 */
class MessageReport extends Model
{
    /** @use HasFactory<MessageReportFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'message_id',
        'reporter_id',
        'reason',
        'details',
        'status',
        'reviewed_at',
        'reviewed_by',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'reason' => MessageReportReason::class,
            'status' => MessageReportStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
