<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A one-off blackout period (holiday, sick day) overriding availability.
 *
 * TZ-2A resolution of TZ-AUD-019 — `timezone` is an ORIGIN/INPUT
 * SNAPSHOT, not an input to any calculation, and its being unread is
 * correct rather than an oversight.
 *
 * InstructorTimeOffService takes the instructor's wall-clock input,
 * interprets it in this timezone, and stores the resulting `starts_at`/
 * `ends_at` as absolute UTC instants. The blackout is then a fixed
 * interval on the timeline: overlap checks compare instants directly
 * (`scopeOverlapping`), which is exactly right and needs no timezone at
 * all. Re-deriving a local time here would be re-doing work that was
 * already done correctly at write time.
 *
 * What the column IS for: audit provenance — answering "which clock was
 * the instructor reading when they blocked this out?" — and rendering a
 * historical entry the way it was entered. Same contract as
 * `bookings.timezone`; see Booking's class docblock.
 *
 * Deliberately retained. It is not dead weight, and it is not a viewer
 * display timezone.
 */
class TeacherUnavailability extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $table = 'teacher_unavailability';

    protected $fillable = [
        'teacher_id',
        'starts_at',
        'ends_at',
        'timezone',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
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

    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }

    /** Blackouts intersecting [$startsAt, $endsAt). */
    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['teacher_id', 'starts_at', 'ends_at', 'timezone', 'reason'])
            ->useLogName('teacher_unavailability')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
