<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseResolutionType;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Enums\SupportCaseType;
use Database\Factories\SupportCaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * SRS Chapter 25 — one support/dispute case. Written
 * exclusively by SupportCaseService; status is written exclusively
 * through TransitionSupportCaseStatusAction. Never hard-deleted
 * (§25.32, "Preserve historical records"). `student_id`/
 * `instructor_id` identify who the case is about, which may differ
 * from `created_by` for admin-created cases (§25.16).
 */
class SupportCase extends Model implements HasMedia
{
    /** @use HasFactory<SupportCaseFactory> */
    use HasFactory, HasUuids, InteractsWithMedia, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'case_number',
        'type',
        'category',
        'priority',
        'status',
        'created_by',
        'student_id',
        'instructor_id',
        'assigned_to',
        'linked_record_type',
        'linked_record_id',
        'subject',
        'description',
        'resolution_type',
        'resolution_summary',
        'opened_at',
        'assigned_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SupportCaseType::class,
            'category' => SupportCaseCategory::class,
            'priority' => SupportCasePriority::class,
            'status' => SupportCaseStatus::class,
            'resolution_type' => SupportCaseResolutionType::class,
            'opened_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** Private: support-case evidence may contain personal/sensitive content and must never sit on the public disk. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportCaseReply::class)->oldest();
    }

    /** Only replies a requester (student/instructor) is permitted to see. */
    public function requesterVisibleReplies(): HasMany
    {
        return $this->replies()->where('visibility', 'requester_visible');
    }

    /**
     * The optional linked record (booking, lesson, payment, invoice,
     * wallet ledger entry, withdrawal request, or instructor user).
     * Display-only — never the authorization boundary (that is
     * LinkedRecordAuthorizer, checked at write time).
     */
    public function linkedRecord(): ?Model
    {
        if ($this->linked_record_type === null || $this->linked_record_id === null) {
            return null;
        }

        return $this->linked_record_type::query()->find($this->linked_record_id);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'assigned_to', 'resolution_type'])
            ->useLogName('support_cases')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
