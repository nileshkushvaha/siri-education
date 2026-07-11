<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\CompensationPayBasis;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The immutable canonical source record for one accrued periodic
 * (daily/weekly/monthly) compensation period. DB-unique per agreement +
 * period, created only by InstructorPeriodicCompensationService inside
 * its accrual transaction; the matching instructor_earnings row points
 * here via source_type='periodic_compensation'. Never modified or
 * deleted after creation.
 */
class InstructorCompensationPeriod extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'agreement_id',
        'instructor_id',
        'pay_basis',
        'period_start',
        'period_end',
        'timezone',
        'amount_minor',
        'currency_id',
        'currency_code',
        'accrued_at',
    ];

    protected function casts(): array
    {
        return [
            'pay_basis' => CompensationPayBasis::class,
            'amount_minor' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'accrued_at' => 'immutable_datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstructorCompensationAgreement::class, 'agreement_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function earning(): HasOne
    {
        return $this->hasOne(InstructorEarning::class, 'source_id')
            ->where('source_type', 'periodic_compensation');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount_minor', 'period_start', 'period_end'])
            ->useLogName('instructor_compensation')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
