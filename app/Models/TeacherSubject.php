<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject (and inclusive grade range) a teacher can teach.
 * Null grade bounds mean the subject is taught at any grade.
 */
class TeacherSubject extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'teacher_id',
        'subject',
        'grade_from',
        'grade_to',
    ];

    protected function casts(): array
    {
        return [
            'grade_from' => 'integer',
            'grade_to' => 'integer',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scopeForSubject(Builder $query, string $subject): Builder
    {
        return $query->where('subject', $subject);
    }

    public function scopeCoveringGrade(Builder $query, int $grade): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('grade_from')->orWhere('grade_from', '<=', $grade))
            ->where(fn (Builder $q) => $q->whereNull('grade_to')->orWhere('grade_to', '>=', $grade));
    }
}
