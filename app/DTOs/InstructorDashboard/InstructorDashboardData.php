<?php

declare(strict_types=1);

namespace App\DTOs\InstructorDashboard;

final readonly class InstructorDashboardData
{
    /**
     * @param  array<int, array<string, mixed>>  $nextLessons
     * @param  array<string, int>  $learningPlans
     * @param  array{pending_reviews: int, recent_submissions: array<int, array<string, mixed>>}  $homework
     * @param  array<int, array{currency: string, available: string, on_hold: string}>  $earnings
     * @param  array{status: mixed, missing: array<int, string>, percentage: int, next_action: string, show_prompt: bool, variant: string}  $onboarding  Phase 23I — show_prompt/variant decide the dashboard onboarding-card visibility and copy; see InstructorDashboardService::onboardingPromptVisible().
     */
    public function __construct(
        public int $upcomingLessons,
        public int $todayLessons,
        public int $completedLessons,
        public float $teachingHours,
        public int $subjectCount,
        public array $nextLessons,
        public array $learningPlans,
        public array $homework,
        public array $earnings,
        public bool $payoutsAvailable,
        public int $unreadNotifications,
        public array $onboarding,
    ) {}
}
