<?php

declare(strict_types=1);

namespace App\Messaging\Services;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Settings\MessagingSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * SRS §17.29 "Messaging Eligibility" — the single source of truth for
 * whether a student and instructor may message each other, computed
 * fresh from current relationship/lifecycle state every time. Used
 * both to open a conversation and to gate every single send
 * (requirement #4 "Enforce eligibility on every send").
 *
 * Context resolution deliberately narrows to Booking or
 * StudentLearningPlan only (§17.30's V1 context set, per requirement
 * #2) — a qualifying lesson (upcoming, or completed within the
 * configured window) resolves to its owning booking rather than
 * becoming its own conversation anchor.
 *
 * §17.28 "Demo-only messaging may be restricted or limited": a
 * confirmed-but-unpaid (free demo) booking never satisfies the first
 * check (payment_status must be Paid), and the lesson-derived checks
 * (upcoming/recently-completed) explicitly require the lesson's own
 * booking type to be paid (`booking.type.is_paid`) — a demo lesson
 * alone, with no other paid/plan relationship, never grants messaging
 * eligibility.
 */
final class MessagingEligibilityService
{
    public function __construct(
        private readonly MessagingSettings $settings,
    ) {}

    public function isEligible(User $student, User $instructor): bool
    {
        return $this->lifecycleEligible($student, $instructor)
            && $this->findEligibleContext($student, $instructor) !== null;
    }

    /**
     * §17.29 "Messaging may be disabled when: ... Instructor is
     * suspended. Student is suspended." Independent of any specific
     * relationship — a blocked lifecycle state disqualifies every
     * conversation for that user, not just one.
     */
    public function lifecycleEligible(User $student, User $instructor): bool
    {
        $studentStatus = $student->profile?->student_status;

        if ($studentStatus !== null && $studentStatus->blocksAccess()) {
            return false;
        }

        $instructorStatus = $instructor->profile?->instructor_status;

        if ($instructorStatus !== null && in_array($instructorStatus, [InstructorStatus::Suspended, InstructorStatus::Archived], true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: class-string<Model>, 1: string}|null the first
     *                                                       qualifying relationship, preferring the most durable
     *                                                       context (booking, then learning plan, then lesson-derived
     *                                                       booking) so a conversation opened today still resolves
     *                                                       sensibly if re-evaluated later.
     */
    public function findEligibleContext(User $student, User $instructor): ?array
    {
        $booking = Booking::query()
            ->where('student_id', $student->id)
            ->where('instructor_id', $instructor->id)
            ->where('status', BookingStatus::Confirmed)
            ->where('payment_status', BookingPaymentStatus::Paid)
            ->latest('starts_at')
            ->first();

        if ($booking !== null) {
            return [Booking::class, (string) $booking->id];
        }

        $plan = StudentLearningPlan::query()
            ->where('student_user_id', $student->id)
            ->where('primary_instructor_user_id', $instructor->id)
            ->where('status', LearningPlanStatus::Active)
            ->latest('created_at')
            ->first();

        if ($plan !== null) {
            return [StudentLearningPlan::class, (string) $plan->id];
        }

        $upcoming = Lesson::query()
            ->where('student_id', $student->id)
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', [LessonStatus::Scheduled, LessonStatus::Live])
            ->where('starts_at', '>', now())
            ->whereHas('booking.type', fn ($q) => $q->where('is_paid', true))
            ->latest('starts_at')
            ->first();

        if ($upcoming !== null) {
            return [Booking::class, (string) $upcoming->booking_id];
        }

        $windowCutoff = now()->subDays($this->settings->post_lesson_window_days);

        $recentlyCompleted = Lesson::query()
            ->where('student_id', $student->id)
            ->where('instructor_id', $instructor->id)
            ->where('status', LessonStatus::Completed)
            ->where('ends_at', '>=', $windowCutoff)
            ->whereHas('booking.type', fn ($q) => $q->where('is_paid', true))
            ->latest('ends_at')
            ->first();

        if ($recentlyCompleted !== null) {
            return [Booking::class, (string) $recentlyCompleted->booking_id];
        }

        return null;
    }

    /** SRS: "conversation context still belongs to both participants." */
    public function contextBelongsToBoth(string $contextType, string $contextId, User $student, User $instructor): bool
    {
        if ($contextType === Booking::class) {
            $booking = Booking::query()->find($contextId);

            return $booking !== null && $booking->student_id === $student->id && $booking->instructor_id === $instructor->id;
        }

        if ($contextType === StudentLearningPlan::class) {
            $plan = StudentLearningPlan::query()->find($contextId);

            return $plan !== null && $plan->student_user_id === $student->id && $plan->primary_instructor_user_id === $instructor->id;
        }

        return false;
    }
}
