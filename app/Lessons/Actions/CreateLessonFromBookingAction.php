<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Services\LessonLearningPlanResolver;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Services\Student\LearningPlanProgressService;
use Illuminate\Support\Facades\DB;

/**
 * Persists the lesson row for a confirmed booking, snapshotting the
 * academic context (subject/topic) from the booking's meta.
 * Eligibility and idempotency are LessonLifecycleService's job — this
 * action only resolves the snapshot and writes.
 *
 * SRS §6.17.5 / §6.17.10: also resolves an optional learning-
 * plan association through LessonLearningPlanResolver — server-side
 * only, never a client-supplied plan id. Demo (unpaid) bookings are
 * never candidates for association: no SRS rule or existing workflow
 * supports attaching a pre-plan demo to a learning plan. A newly
 * associated plan is recalculated in the same transaction as the
 * lesson write, since it has no prior lesson evidence to have already
 * counted.
 */
final class CreateLessonFromBookingAction
{
    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly LessonLearningPlanResolver $planResolver,
        private readonly LearningPlanProgressService $progress,
    ) {}

    public function execute(Booking $booking): Lesson
    {
        $meta = $booking->meta ?? [];
        $topic = $this->resolveTopic($meta['topic_id'] ?? null);
        $subjectId = $topic?->subject_id ?? $this->resolveSubjectId($meta['subject'] ?? null);
        $academicLevelId = $this->resolveAcademicLevelId($meta['grade'] ?? null);

        $plan = $booking->type?->is_paid === true
            ? $this->planResolver->resolve($booking->student_id, $subjectId, $academicLevelId, $booking->instructor_id)
            : null;

        return DB::transaction(function () use ($booking, $subjectId, $topic, $academicLevelId, $meta, $plan): Lesson {
            $lesson = $this->lessons->create([
                'booking_id' => $booking->id,
                'student_id' => $booking->student_id,
                'instructor_id' => $booking->instructor_id,
                'subject_id' => $subjectId,
                'subject_topic_id' => $topic?->id,
                // Bookings carry a grade int, not a level id — resolve the
                // level only when it is unambiguous; the raw grade is kept
                // in the sanitized metadata snapshot below either way.
                'academic_level_id' => $academicLevelId,
                'learning_plan_id' => $plan?->id,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'timezone' => $booking->timezone ?? 'UTC',
                'status' => LessonStatus::Scheduled,
                'student_attendance_status' => LessonAttendanceStatus::Pending,
                'instructor_attendance_status' => LessonAttendanceStatus::Pending,
                'metadata' => $this->sanitizedSnapshot($booking, $meta),
            ]);

            if ($plan !== null) {
                $this->progress->recalculate($plan, null);
            }

            return $lesson;
        });
    }

    private function resolveTopic(mixed $topicId): ?SubjectTopic
    {
        if (! is_string($topicId) || $topicId === '') {
            return null;
        }

        return SubjectTopic::query()->find($topicId);
    }

    /**
     * Bookings snapshot a raw grade int (1-12), not a level id. The
     * level FK is filled only when exactly one active academic level
     * covers the grade — with overlapping or country-specific levels
     * the match is ambiguous and the FK stays null (the grade in
     * metadata remains the source of truth).
     */
    private function resolveAcademicLevelId(mixed $grade): ?string
    {
        if (! is_int($grade)) {
            return null;
        }

        $matches = AcademicLevel::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('min_grade')->orWhere('min_grade', '<=', $grade))
            ->where(fn ($q) => $q->whereNull('max_grade')->orWhere('max_grade', '>=', $grade))
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveSubjectId(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Subject::query()->where('slug', $slug)->value('id');
    }

    /**
     * Whitelisted scalars only — never raw booking meta, and never
     * price/payment/wallet data.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitizedSnapshot(Booking $booking, array $meta): array
    {
        return array_filter([
            'booking_reference' => $booking->reference,
            'booking_type' => $booking->type?->key,
            'subject' => is_string($meta['subject'] ?? null) ? $meta['subject'] : null,
            'topic' => is_string($meta['topic'] ?? null) ? $meta['topic'] : null,
            'grade' => is_int($meta['grade'] ?? null) ? $meta['grade'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
