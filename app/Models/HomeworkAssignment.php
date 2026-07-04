<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HomeworkAssignment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'teacher_id',
        'student_id',
        'subject',
        'title',
        'description',
        'due_at',
        'status',
        'submission_text',
        'submitted_at',
        'grade',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'status' => HomeworkStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', HomeworkStatus::Pending)->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === HomeworkStatus::Pending && $this->due_at->isPast();
    }
}
