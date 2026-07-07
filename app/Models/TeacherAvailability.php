<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\Weekday;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A recurring weekly availability window for a teacher, optionally
 * bounded by an effective date range (schedule versions).
 */
class TeacherAvailability extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $table = 'teacher_availability';

    protected $fillable = [
        'teacher_id',
        'day_of_week',
        'start_time',
        'end_time',
        'timezone',
        'effective_from',
        'effective_until',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => Weekday::class,
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeForDay(Builder $query, Weekday $day): Builder
    {
        return $query->where('day_of_week', $day);
    }

    /** Windows whose effective range covers the given date. */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['teacher_id', 'day_of_week', 'start_time', 'end_time', 'timezone', 'effective_from', 'effective_until', 'is_active'])
            ->useLogName('teacher_availability')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
