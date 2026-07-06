<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\User;
use Illuminate\Support\Collection;

final class StudentDashboardService
{
    public function __construct(
        private readonly StudentBookingServiceInterface $bookings,
        private readonly HomeworkServiceInterface $homework,
        private readonly StudentFavoriteInstructorService $favorites,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $student): array
    {
        $student->loadMissing([
            'profile.country.defaultCurrency',
            'profile.studentAcademicLevel',
            'profile.studentPreferredLanguage',
            'preferredSubjects',
        ]);

        $upcoming = $this->bookings->upcomingClasses($student);
        $progress = $this->bookings->progressStats($student);
        $homeworkStats = $this->homework->statsForStudent($student->id);
        $activeGoals = $student->studentLearningGoals()
            ->with(['subject', 'academicLevel'])
            ->activeForDashboard()
            ->latest()
            ->take(4)
            ->get();
        $activePlans = $student->studentLearningPlans()
            ->with(['subject', 'primaryInstructor', 'milestones'])
            ->activeForDashboard()
            ->latest()
            ->take(2)
            ->get();
        $favoriteInstructors = $this->favorites->bookableFavorites($student, 4);
        $profileCompletion = $this->profileCompletion($student);

        return [
            'profile_completion' => $profileCompletion,
            'profile_missing_items' => $this->profileMissingItems($student),
            'preferred_subjects' => $student->preferredSubjects,
            'active_goals' => $activeGoals,
            'active_learning_plans' => $activePlans,
            'active_learning_plan_count' => $student->studentLearningPlans()->activeForDashboard()->count(),
            'current_learning_plan' => $activePlans->first(),
            'favorite_instructors' => $favoriteInstructors,
            'favorite_instructor_count' => $student->favoriteInstructorRows()->count(),
            'bookable_favorite_instructor_count' => $favoriteInstructors->count(),
            'upcoming_count' => $upcoming->count(),
            'next_classes' => $upcoming->take(3),
            'completed_sessions' => (int) $progress->completed_sessions,
            'total_hours' => round((float) $progress->total_hours, 1),
            'pending_homework_count' => (int) ($homeworkStats->pending ?? 0),
            'overdue_homework_count' => (int) ($homeworkStats->overdue ?? 0),
            'default_currency' => $student->profile?->country?->defaultCurrency,
            'safe_placeholders' => [
                'wallet' => 'Wallet setup will be available in a later phase.',
                'payments' => 'Payment history will appear after bookings are paid.',
                'meetings' => 'Meeting links will appear after lessons are scheduled.',
            ],
            'recommended_next_action' => $this->recommendedNextAction($profileCompletion, $activeGoals, $favoriteInstructors, $upcoming),
        ];
    }

    private function profileCompletion(User $student): int
    {
        $profile = $student->profile;
        $checks = [
            filled($student->first_name) || filled($student->name),
            filled($student->last_name) || filled($student->name),
            filled($profile?->phone),
            filled($profile?->country_id),
            filled($profile?->timezone),
            filled($profile?->student_academic_level_id),
            filled($profile?->student_preferred_language_id) || filled($profile?->language),
            $student->preferredSubjects->isNotEmpty(),
            filled($profile?->avatarUrl),
        ];

        return (int) round((collect($checks)->filter()->count() / count($checks)) * 100);
    }

    /**
     * @return array<int, string>
     */
    private function profileMissingItems(User $student): array
    {
        $profile = $student->profile;
        $items = [];

        if (! filled($profile?->student_academic_level_id)) {
            $items[] = 'current academic level';
        }

        if ($student->preferredSubjects->isEmpty()) {
            $items[] = 'preferred subjects';
        }

        if (! filled($profile?->student_preferred_language_id) && ! filled($profile?->language)) {
            $items[] = 'preferred language';
        }

        return $items;
    }

    private function recommendedNextAction(int $profileCompletion, Collection $activeGoals, Collection $favoriteInstructors, Collection $upcoming): string
    {
        if ($profileCompletion < 80) {
            return 'complete_profile';
        }

        if ($activeGoals->isEmpty()) {
            return 'create_learning_goal';
        }

        if ($favoriteInstructors->isEmpty() && $upcoming->isEmpty()) {
            return 'browse_instructors';
        }

        return 'continue_learning';
    }
}
