<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkResourceCollection;
use App\Homework\Enums\HomeworkStatus;
use App\Support\Media\Concerns\HasStandardImageConversions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class HomeworkAssignment extends Model implements HasMedia
{
    use HasFactory, HasStandardImageConversions, HasUuids, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'learning_plan_id',
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

    /**
     * Instructor-provided learning resources and the student's
     * own submission attachment, both private (local disk, never the
     * public disk) and served only through the policy-rechecked
     * download route — never a public URL.
     */
    public function registerMediaCollections(): void
    {
        foreach (HomeworkResourceCollection::cases() as $collection) {
            $this->addMediaCollection($collection->value)
                ->useDisk('local')
                ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
        }
    }

    /** Small private preview for image resources/submissions; PDFs are never converted (mime-guarded), stay download-only. */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addPreviewConversion(...array_map(fn (HomeworkResourceCollection $c): string => $c->value, HomeworkResourceCollection::cases()));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Resolves soft-deleted plans so historical
     * homework context stays visible after a plan is archived/removed.
     */
    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(StudentLearningPlan::class, 'learning_plan_id')->withTrashed();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** Claimed/dispatched due-date reminder history. */
    public function dueReminders(): HasMany
    {
        return $this->hasMany(HomeworkDueReminder::class, 'homework_assignment_id');
    }

    /** Reusable library resource versions attached to this assignment. */
    public function resourceVersions(): BelongsToMany
    {
        return $this->belongsToMany(HomeworkResourceVersion::class, 'homework_assignment_resources')
            ->withPivot('attached_by')
            ->withTimestamps();
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
