<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\Enums\BookingStatus;
use App\DTOs\InstructorDashboard\InstructorDashboardData;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Enums\LearningPlanStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\InstructorEarning;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;

final class InstructorDashboardService
{
    public function __construct(
        private readonly InstructorOnboardingService $onboarding,
        private readonly InstructorPayoutEligibility $payoutEligibility,
    ) {}

    public function summary(User $instructor, int $unreadNotifications = 0): InstructorDashboardData
    {
        $timezone = $instructor->profile?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        $upcoming = Booking::query()
            ->active()
            ->upcoming()
            ->forInstructor($instructor->id)
            ->with(['type:id,name', 'student:id,first_name,last_name,name'])
            ->orderBy('starts_at')
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

        return new InstructorDashboardData(
            upcomingLessons: $upcoming->count(),
            todayLessons: $upcoming->filter(fn (Booking $booking): bool => $booking->starts_at->timezone($timezone)->isSameDay($now))->count(),
            completedLessons: (int) ($completed?->lesson_count ?? 0),
            teachingHours: round(((int) ($completed?->teaching_minutes ?? 0)) / 60, 1),
            subjectCount: $instructor->teacherSubjects()->count(),
            nextLessons: $upcoming->take(4)->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'subject' => $booking->meta['subject'] ?? $booking->type?->name ?? 'Lesson',
                'type' => $booking->type?->name ?? 'Class',
                'student' => $booking->student?->name ?? 'Student',
                'starts_at' => $booking->starts_at->timezone($timezone),
                'ends_at' => $booking->ends_at->timezone($timezone),
                'status' => $booking->status->label(),
            ])->all(),
            learningPlans: [
                'active' => (clone $plans)->whereIn('status', [LearningPlanStatus::Active, LearningPlanStatus::Paused, LearningPlanStatus::ReviewDue])->count(),
                'reviews_due' => (clone $plans)->where('status', LearningPlanStatus::ReviewDue)->count(),
                'assessments_due' => (clone $plans)->where('status', LearningPlanStatus::AwaitingAssessment)->whereDoesntHave('assessments')->count(),
            ],
            homework: [
                'pending_reviews' => (clone $submittedHomework)->count(),
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
            onboarding: $this->onboarding->progress($instructor),
        );
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
