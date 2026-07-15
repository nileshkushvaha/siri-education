<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\ReviewReportStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\ReviewReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One report against one published public review. Many reports may
 * exist for the same review (one row per reporter per reason).
 * Written exclusively by SubmitReviewReportAction (creation) and
 * ReviewReportService's resolution methods (status/resolution
 * fields) — never physically deleted, and never the direct writer of
 * `lesson_reviews.status`; a resolution that changes review
 * visibility always delegates to ReviewModerationService.
 */
class ReviewReport extends Model
{
    /** @use HasFactory<ReviewReportFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'review_id',
        'reporter_id',
        'reason',
        'explanation',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'resolution_reason',
        'resolution_action',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReviewReportReason::class,
            'status' => ReviewReportStatus::class,
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'resolution_action' => ReviewReportResolutionAction::class,
            'version' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(LessonReview::class, 'review_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'resolution_action'])
            ->useLogName('reviews')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
