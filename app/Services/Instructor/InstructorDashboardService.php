<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingJoinAvailability;
use App\DTOs\InstructorDashboard\InstructorDashboardData;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\WaitlistEntryStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\InstructorEarning;
use App\Models\InstructorWaitlistEntry;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Settings\MeetingSettings;
use App\Support\MoneyFormatter;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;

final class InstructorDashboardService
{
    public function __construct(
        private readonly InstructorOnboardingService $onboarding,
        private readonly InstructorPayoutEligibility $payoutEligibility,
        private readonly BookingMeetingServiceInterface $meetings,
        private readonly MeetingSettings $meetingSettings,
    ) {}

    /**
     * $pendingHomeworkReviews mirrors $unreadNotifications: when the
     * caller already has the count (PortalBadgeService, via
     * AccountPortalComposer, computes the identical
     * pendingReviewCountForTeacher() value for the nav badge on every
     * request this widget renders on), pass it in to avoid running the
     * same COUNT query twice in one request. Null preserves the original
     * self-contained behavior for any other caller.
     */
    public function summary(User $instructor, int $unreadNotifications = 0, ?int $pendingHomeworkReviews = null): InstructorDashboardData
    {
        $timezone = UserTimezoneResolver::resolve($instructor);
        $now = CarbonImmutable::now($timezone);

        // Three bounded queries (two COUNTs + one LIMIT 4) instead
        // of materializing every upcoming booking just to count/filter/take()
        // it in PHP. Day boundaries are computed in the instructor's own
        // timezone, then converted to the app timezone Booking::starts_at is
        // actually stored/compared in (see Booking::scopeUpcoming()).
        $upcomingQuery = Booking::query()->active()->upcoming()->forInstructor($instructor->id);

        $upcomingCount = (clone $upcomingQuery)->count();

        $todayStart = $now->startOfDay()->setTimezone(config('app.timezone'));
        $todayEnd = $now->endOfDay()->setTimezone(config('app.timezone'));
        $todayCount = (clone $upcomingQuery)->whereBetween('starts_at', [$todayStart, $todayEnd])->count();

        $nextLessons = (clone $upcomingQuery)
            ->with(['type:id,name', 'student:id,first_name,last_name,name', 'meeting', 'lesson:id,booking_id,status'])
            ->orderBy('starts_at')
            ->limit(4)
            ->get();

        $completed = Booking::query()
            ->forInstructor($instructor->id)
            ->where('status', BookingStatus::Completed)
            ->selectRaw('COUNT(*) as lesson_count, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)), 0) as teaching_minutes')
            ->first();

        $plans = StudentLearningPlan::query()->where('primary_instructor_user_id', $instructor->id);
        $submittedHomework = HomeworkAssignment::query()
            ->forTeacher($instructor->id)
            ->where('status', HomeworkStatus::Submitted);

        $status = $instructor->profile?->instructor_status;

        return new InstructorDashboardData(
            upcomingLessons: $upcomingCount,
            todayLessons: $todayCount,
            completedLessons: (int) ($completed?->lesson_count ?? 0),
            teachingHours: round(((int) ($completed?->teaching_minutes ?? 0)) / 60, 1),
            subjectCount: $instructor->teacherSubjects()->count(),
            nextLessons: $nextLessons->map(function (Booking $booking) use ($timezone): array {
                // Bounded to the 4 lessons fetched above; `meeting`/`lesson`
                // are eager-loaded, so this never queries per row.
                $joinAvailability = $this->meetings->joinAvailabilityFor(
                    $booking,
                    $this->meetingSettings->instructor_join_url_visible,
                    $booking->lesson?->status,
                );

                return [
                    'id' => $booking->id,
                    'reference' => $booking->reference,
                    'subject' => $booking->meta['subject'] ?? $booking->type?->name ?? 'Lesson',
                    'type' => $booking->type?->name ?? 'Class',
                    'student' => $booking->student?->name ?? 'Student',
                    'starts_at' => $booking->starts_at->timezone($timezone),
                    'ends_at' => $booking->ends_at->timezone($timezone),
                    'status' => $booking->status->label(),
                    'join_url' => $joinAvailability === MeetingJoinAvailability::Available ? $booking->meeting?->join_url : null,
                ];
            })->all(),
            learningPlans: [
                'active' => (clone $plans)->whereIn('status', [LearningPlanStatus::Active, LearningPlanStatus::Paused, LearningPlanStatus::ReviewDue])->count(),
                'reviews_due' => (clone $plans)->where('status', LearningPlanStatus::ReviewDue)->count(),
                'assessments_due' => (clone $plans)->where('status', LearningPlanStatus::AwaitingAssessment)->whereDoesntHave('assessments')->count(),
            ],
            homework: [
                'pending_reviews' => $pendingHomeworkReviews ?? (clone $submittedHomework)->count(),
                'recent_submissions' => (clone $submittedHomework)->with('student:id,first_name,last_name,name')->latest('submitted_at')->limit(3)->get()->map(fn (HomeworkAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'subject' => $assignment->subject,
                    'student' => $assignment->student?->name ?? 'Student',
                    'submitted_at' => $assignment->submitted_at?->timezone($timezone),
                ])->all(),
            ],
            earnings: $this->earnings($instructor),
            payoutsAvailable: $this->payoutEligibility->isEligible($instructor),
            unreadNotifications: $unreadNotifications,
            onboarding: [
                ...$this->onboarding->progress($instructor),
                'show_prompt' => $this->onboardingPromptVisible($status),
                'variant' => $status === InstructorStatus::Rejected ? 'rejected' : 'in_progress',
            ],
            waitlistDemandCount: InstructorWaitlistEntry::query()
                ->where('instructor_user_id', $instructor->id)
                ->where('status', WaitlistEntryStatus::Waiting->value)
                ->count(),
        );
    }

    /**
     * The dashboard onboarding card must never resurface for an
     * instructor who has already cleared review, regardless of a stale
     * completeness percentage (e.g. an admin Force Approve bypasses the
     * normal completeness gate, which could otherwise leave `percentage`
     * under 100 forever for an already-Active instructor). Rejected is
     * shown with different copy (see the 'variant' key above) — there is no
     * reapply transition in InstructorOnboardingService, so no reapply
     * action is offered here, only a status message.
     */
    private function onboardingPromptVisible(?InstructorStatus $status): bool
    {
        return ! in_array($status, [
            InstructorStatus::Approved,
            InstructorStatus::Active,
            InstructorStatus::Vacation,
            InstructorStatus::Suspended,
            InstructorStatus::Archived,
        ], true);
    }

    /** @return array<int, array{currency: string, available: string, on_hold: string}> */
    private function earnings(User $instructor): array
    {
        return InstructorEarning::query()
            ->forInstructor($instructor->id)
            ->whereIn('status', [InstructorEarningStatus::Releasable, InstructorEarningStatus::PendingHold])
            ->selectRaw('currency_code, status, SUM(earning_amount_minor) as total_minor')
            ->groupBy('currency_code', 'status')
            ->get()
            ->groupBy('currency_code')
            ->map(function ($rows, string $currency): array {
                $available = (int) ($rows->firstWhere('status', InstructorEarningStatus::Releasable)?->total_minor ?? 0);
                $onHold = (int) ($rows->firstWhere('status', InstructorEarningStatus::PendingHold)?->total_minor ?? 0);

                return [
                    'currency' => $currency,
                    'available' => MoneyFormatter::format($available, $currency),
                    'on_hold' => MoneyFormatter::format($onHold, $currency),
                ];
            })->values()->all();
    }
}
