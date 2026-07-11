<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\SettlementBatchStatus;
use Database\Factories\InstructorSettlementBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One instructor + one currency settlement unit. Status changes go
 * exclusively through InstructorEarningService; marking a batch paid
 * is a manual admin action — no external transfer happens here.
 */
class InstructorSettlementBatch extends Model
{
    /** @use HasFactory<InstructorSettlementBatchFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'batch_reference',
        'instructor_id',
        'currency_code',
        'total_amount_minor',
        'status',
        'period_start',
        'period_end',
        'approved_at',
        'approved_by',
        'paid_at',
        'payment_reference',
        'notes',
        'metadata',
    ];

    /** Admin internals — never serialized to instructors. */
    protected $hidden = [
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SettlementBatchStatus::class,
            'total_amount_minor' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InstructorSettlementBatch $batch): void {
            $batch->batch_reference ??= 'ISB-'.strtoupper(Str::random(10));
        });
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(InstructorEarning::class, 'settlement_batch_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_amount_minor', 'paid_at'])
            ->useLogName('instructor_earnings')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
