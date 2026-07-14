<?php

declare(strict_types=1);

namespace App\Models;

use App\Quality\Enums\InstructorQualityAlertResolutionAction;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use Database\Factories\InstructorQualityAlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One durable, deduplicated quality-risk signal against an
 * instructor. Written exclusively by the Quality domain's detection
 * actions (creation) and InstructorQualityAlertService (admin
 * resolution) — never physically deleted. `needs_reevaluation` is a
 * non-destructive staleness flag: when a review/lesson underlying an
 * alert later changes (hidden, outcome overridden), the alert is
 * flagged for human attention rather than silently deleted or
 * mutated.
 */
class InstructorQualityAlert extends Model
{
    /** @use HasFactory<InstructorQualityAlertFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $table = 'quality_alerts';

    protected $fillable = [
        'instructor_id',
        'alert_type',
        'severity',
        'status',
        'source_type',
        'source_id',
        'detection_fingerprint',
        'triggered_at',
        'signal_window_start',
        'signal_window_end',
        'signal_count',
        'threshold_snapshot',
        'summary_metadata',
        'needs_reevaluation',
        'reevaluated_at',
        'assigned_to',
        'reviewed_at',
        'reviewed_by',
        'resolved_at',
        'resolution_action',
        'resolution_reason',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'alert_type' => InstructorQualityAlertType::class,
            'severity' => InstructorQualityAlertSeverity::class,
            'status' => InstructorQualityAlertStatus::class,
            'source_type' => QualityAlertSourceType::class,
            'triggered_at' => 'immutable_datetime',
            'signal_window_start' => 'immutable_datetime',
            'signal_window_end' => 'immutable_datetime',
            'signal_count' => 'integer',
            'threshold_snapshot' => 'array',
            'summary_metadata' => 'array',
            'needs_reevaluation' => 'boolean',
            'reevaluated_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolution_action' => InstructorQualityAlertResolutionAction::class,
            'version' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'resolution_action', 'needs_reevaluation'])
            ->useLogName('quality')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
