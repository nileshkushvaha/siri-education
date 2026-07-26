<?php

declare(strict_types=1);

namespace App\DTOs\StudentDashboard;

final readonly class StudentDashboardData
{
    /**
     * @param  array<string, mixed>|null  $nextLesson
     * @param  array{pending: int, overdue: int, items: array<int, array<string, mixed>>}|null  $homework
     * @param  array<string, mixed>|null  $learningJourney
     * @param  array<string, mixed>|null  $wallet
     * @param  array<string, mixed>|null  $referral
     * @param  array<int, array<string, mixed>>|null  $favorites
     * @param  array{unread: int, items: array<int, array<string, mixed>>}|null  $notifications
     * @param  array{completion: int, missing: array<int, string>}|null  $profile
     * @param  array<int, array<string, mixed>>|null  $recommendedInstructors
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public ?array $nextLesson,
        public ?array $homework,
        public ?array $learningJourney,
        public ?array $wallet,
        public ?array $referral,
        public ?array $favorites,
        public ?array $notifications,
        public ?array $profile,
        public ?array $recommendedInstructors = null,
        public array $errors = [],
    ) {}
}
