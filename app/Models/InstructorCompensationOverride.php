<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An hourly-rate override scoped to one compensation agreement
 * (subject / education level / lesson duration dimensions). Inherits
 * the agreement's currency and effective window; editable only while
 * the agreement is draft/scheduled. Periodic agreements never use
 * overrides. Written exclusively by
 * InstructorCompensationAgreementService.
 */
class InstructorCompensationOverride extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'agreement_id',
        'subject_id',
        'academic_level_id',
        'duration_minutes',
        'amount_minor',
        'combo_key',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstructorCompensationAgreement::class, 'agreement_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    /** NULL-safe dimension key backing the DB uniqueness. */
    public static function comboKey(?string $subjectId, ?string $academicLevelId, ?int $durationMinutes): string
    {
        return implode('|', [$subjectId ?? '-', $academicLevelId ?? '-', $durationMinutes ?? '-']);
    }

    /** Higher = more specific = wins the resolution priority. */
    public function specificity(): int
    {
        return ($this->subject_id !== null ? 4 : 0)
            + ($this->academic_level_id !== null ? 2 : 0)
            + ($this->duration_minutes !== null ? 1 : 0);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount_minor', 'subject_id', 'academic_level_id', 'duration_minutes'])
            ->useLogName('instructor_compensation')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
