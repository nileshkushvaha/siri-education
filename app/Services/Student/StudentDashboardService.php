<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Enums\MeetingStatus;
use App\DTOs\StudentDashboard\StudentDashboardData;
use App\Enums\LearningPlanMilestoneStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\LearningPlanMilestone;
use App\Models\LearningPlanReview;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Enums\ReferralRewardStatus;
use App\Services\Profile\ProfileService;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use App\Wallet\Support\WalletMoneyFormatter;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class StudentDashboardService
{
    public function __construct(
        private readonly StudentBookingServiceInterface $bookings,
        private readonly HomeworkServiceInterface $homework,
        private readonly StudentFavoriteInstructorService $favorites,
        private readonly ProfileService $profiles,
        private readonly FeatureSettings $features,
        private readonly MeetingSettings $meetings,
    ) {}

    public function summary(User $student, int $unreadCount = 0): StudentDashboardData
    {
        $errors = [];

        return new StudentDashboardData(
            nextLesson: $this->widget('next lesson', fn () => $this->nextLesson($student), $errors),
            homework: $this->features->homework_enabled
                ? $this->widget('homework', fn () => $this->homework($student), $errors)
                : null,
            learningJourney: $this->widget('learning journey', fn () => $this->learningJourney($student), $errors),
            wallet: $this->features->wallet_enabled
                ? $this->widget('wallet', fn () => $this->wallet($student), $errors)
                : null,
            referral: $this->features->referral_enabled
                ? $this->widget('referral', fn () => $this->referral($student), $errors)
                : null,
            favorites: $this->widget('favorite instructors', fn () => $this->favoriteInstructors($student), $errors),
            notifications: $this->widget('notifications', fn () => $this->notifications($student, $unreadCount), $errors),
            profile: $this->widget('profile', fn () => $this->profile($student), $errors),
            errors: $errors,
        );
    }

    /** @param array<int, string> $errors */
    private function widget(string $name, callable $read, array &$errors): mixed
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);
            $errors[] = $name;

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function nextLesson(User $student): ?array
    {
        $booking = $this->bookings->upcomingClasses($student, 1)->first();
        if ($booking === null) {
            return null;
        }

        $timezone = $student->profile?->timezone ?: config('app.timezone');
        $meeting = $booking->meeting;
        $windowStartsAt = $meeting?->starts_at ?? $booking->starts_at;
        $windowEndsAt = $meeting?->ends_at ?? $booking->ends_at;
        $windowOpen = now()->betweenIncluded(
            $windowStartsAt->subMinutes($this->meetings->meeting_link_visible_before_minutes),
            $windowEndsAt->addMinutes($this->meetings->meeting_link_visible_after_minutes),
        );
        $joinUrl = $meeting?->status === MeetingStatus::Created
            && $this->meetings->student_join_url_visible
            && $windowOpen
            ? $meeting->join_url
            : null;

        return [
            'reference' => $booking->reference,
            'subject' => $booking->meta['subject'] ?? $booking->type?->name ?? 'Lesson',
            'instructor' => $booking->instructor?->name ?? 'Instructor to be assigned',
            'type' => $booking->type?->name ?? 'Class',
            'starts_at' => $booking->starts_at->timezone($timezone),
            'meeting_status' => $meeting?->status?->label() ?? 'Not scheduled',
            'join_url' => $joinUrl,
            'join_window_open' => $windowOpen,
            'can_cancel' => Gate::forUser($student)->allows('cancel', $booking),
            'can_reschedule' => Gate::forUser($student)->allows('reschedule', $booking),
        ];
    }

    /** @return array{pending: int, overdue: int, items: array<int, array<string, mixed>>} */
    private function homework(User $student): array
    {
        $stats = $this->homework->statsForStudent($student->id);
        $timezone = $student->profile?->timezone ?: config('app.timezone');

        return [
            'pending' => (int) ($stats->pending ?? 0),
            'overdue' => (int) ($stats->overdue ?? 0),
            'items' => $this->homework->attentionForStudent($student->id, 3)->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'subject' => $item->subject ?: ($item->booking?->type?->name ?? 'Homework'),
                'due_at' => $item->due_at->timezone($timezone),
                'overdue' => $item->isOverdue(),
                'status' => $item->status->label(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function learningJourney(User $student): ?array
    {
        $plan = $student->studentLearningPlans()->activeForDashboard()
            ->with(['subject:id,name', 'primaryInstructor:id,name', 'learningGoal:id,title'])
            ->latest()->first();
        if ($plan === null) {
            return null;
        }

        $total = LearningPlanMilestone::query()->where('learning_plan_id', $plan->id)->count();
        $completed = LearningPlanMilestone::query()->where('learning_plan_id', $plan->id)
            ->where('status', LearningPlanMilestoneStatus::Completed)->count();
        $next = LearningPlanMilestone::query()->where('learning_plan_id', $plan->id)
            ->whereIn('status', [LearningPlanMilestoneStatus::Pending, LearningPlanMilestoneStatus::InProgress])
            ->orderBy('sort_order')->value('title');
        $review = LearningPlanReview::query()->where('learning_plan_id', $plan->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->first(['reviewed_at', 'summary']);

        return [
            'title' => $plan->title,
            'subject' => $plan->subject?->name,
            'instructor' => $plan->primaryInstructor?->name,
            'goal' => $plan->learningGoal?->title,
            'progress' => $plan->progress_percent,
            'completed_milestones' => $completed,
            'total_milestones' => $total,
            'next_milestone' => $next,
            'last_review_at' => $review?->reviewed_at,
            'last_review' => $review?->summary,
        ];
    }

    /** @return array<string, mixed>|null */
    private function wallet(User $student): ?array
    {
        $wallet = Wallet::query()->forUser($student->id)->with('currency')->first();
        if ($wallet === null) {
            return null;
        }
        $latest = WalletLedgerEntry::query()->forWallet($wallet->id)->posted()->latest('posted_at')->first();

        return [
            'available' => WalletMoneyFormatter::format($wallet->available_balance_minor, $wallet->currency, $wallet->currency_code),
            'latest' => $latest?->description,
            'latest_at' => $latest?->posted_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function referral(User $student): ?array
    {
        $code = ReferralCode::query()->where('user_id', $student->id)->first();
        $query = ReferralReward::query()->forReferrer($student->id);

        return [
            'code' => $code?->isActive() ? $code->code : null,
            'reward_count' => (clone $query)->count(),
            'credited_count' => (clone $query)->where('status', ReferralRewardStatus::Credited)->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function favoriteInstructors(User $student): array
    {
        return $this->favorites->bookableFavorites($student, 3)->map(fn (User $instructor) => [
            'name' => $instructor->name,
            'slug' => $instructor->slug,
            'avatar' => $instructor->profile?->avatarUrl,
        ])->all();
    }

    /** @return array{unread: int, items: array<int, array<string, mixed>>} */
    private function notifications(User $student, int $unreadCount): array
    {
        return [
            'unread' => $unreadCount,
            'items' => $student->notifications()->latest()->limit(3)->get()->map(fn ($notification) => [
                'title' => $notification->data['title'] ?? class_basename($notification->type),
                'created_at' => $notification->created_at,
                'read' => $notification->read_at !== null,
            ])->all(),
        ];
    }

    /** @return array{completion: int, missing: array<int, string>} */
    private function profile(User $student): array
    {
        $student->loadMissing(['profile', 'preferredSubjects']);
        $missing = [];
        if (! $student->profile?->student_academic_level_id) {
            $missing[] = 'academic level';
        }
        if ($student->preferredSubjects->isEmpty()) {
            $missing[] = 'preferred subjects';
        }
        if (! $student->profile?->student_preferred_language_id && ! $student->profile?->language) {
            $missing[] = 'preferred language';
        }

        return ['completion' => $this->profiles->completion($student), 'missing' => $missing];
    }
}
