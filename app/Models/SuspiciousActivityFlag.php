<?php

declare(strict_types=1);

namespace App\Models;

use App\Compliance\Enums\SuspiciousActivityCategory;
use App\Compliance\Enums\SuspiciousActivityFlagDecision;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One durable, deduplicated compliance signal against a subject user.
 * Written exclusively by ComplianceMonitoringService — never directly
 * by a controller, listener, job, or Filament action. A flag is
 * evidence for human review, never proof of fraud and never a
 * sanction; nothing reads this model to gate booking, payment,
 * wallet, or referral behavior.
 */
class SuspiciousActivityFlag extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    protected $table = 'suspicious_activity_flags';

    protected $fillable = [
        'reference',
        'rule_code',
        'rule_version',
        'category',
        'severity',
        'status',
        'subject_id',
        'actor_id',
        'occurrence_count',
        'first_observed_at',
        'last_observed_at',
        'evidence',
        'threshold_snapshot',
        'fingerprint',
        'active_fingerprint',
        'reviewer_id',
        'decision',
        'decision_reason',
        'reviewed_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'rule_code' => SuspiciousActivityRuleCode::class,
            'rule_version' => 'integer',
            'category' => SuspiciousActivityCategory::class,
            'severity' => SuspiciousActivityFlagSeverity::class,
            'status' => SuspiciousActivityFlagStatus::class,
            'occurrence_count' => 'integer',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'evidence' => 'array',
            'threshold_snapshot' => 'array',
            'decision' => SuspiciousActivityFlagDecision::class,
            'reviewed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'decision', 'occurrence_count'])
            ->useLogName('compliance')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
