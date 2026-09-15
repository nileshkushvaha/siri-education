<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\DTOs\StudentJoinState;
use App\DTOs\StudentDashboard\StudentDashboardData;
use App\Enums\LearningPlanMilestoneStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\Booking;
use App\Models\LearningPlanMilestone;
use App\Models\LearningPlanReview;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Enums\ReferralRewardStatus;
use App\Services\Instructor\RecommendationService;
use App\Services\Profile\ProfileService;
use App\Settings\FeatureSettings;
use App\Support\UserTimezoneResolver;
use App\Wallet\Support\WalletMoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class StudentDashboardService
{
    /** The hero plus the short list beneath it. */
    private const int SCHEDULE_LIMIT = 5;

    public function __construct(
        private readonly HomeworkServiceInterface $homework,
        private readonly StudentFavoriteInstructorService $favorites,
        private readonly ProfileService $profiles,
        private readonly FeatureSettings $features,
        private readonly StudentScheduleService $schedule,
        private readonly RecommendationService $recommendations,
        private readonly StudentBookingJourneyService $bookingJourneys,
        private readonly Request $request,
    ) {}

    public function summary(User $student, int $unreadCount = 0): StudentDashboardData
    {
        $errors = [];

        // One schedule read feeds both the hero (first lesson) and the
        // short list beneath it.
        $schedule = $this->widget('next lesson', fn () => $this->schedule->upcoming($student, self::SCHEDULE_LIMIT), $errors);

        return new StudentDashboardData(
            nextLesson: $schedule === null ? null : $this->nextLesson($student, $schedule->first()),
            upcomingLessons: $schedule === null ? null : $schedule->slice(1)->map(fn (array $row): array => $this->lessonRow($student, $row))->values()->all(),
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
            recommendedInstructors: $this->widget(
                'recommended instructors',
                fn () => $this->recommendations->recommendedForYou($student, $this->request, 4)->all(),
                $errors,
            ),
            errors: $errors,
            bookingJourney: $this->widget('booking journey', fn () => $this->bookingJourneys->for($student), $errors),
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

    /**
     * The hero: the soonest lesson not yet ended (a lesson in progress
     * stays here until it ends, since its join window is still open).
     *
     * @param  array{booking: Booking, join: StudentJoinState, today: bool}|null  $row
     * @return array<string, mixed>|null
     */
    private function nextLesson(User $student, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $booking = $row['booking'];

        return [
            ...$this->lessonRow($student, $row),
            'meeting_status' => $booking->meeting?->status?->label() ?? 'Not scheduled',
            'can_cancel' => Gate::forUser($student)->allows('cancel', $booking),
            'can_reschedule' => Gate::forUser($student)->allows('reschedule', $booking),
        ];
    }

    /**
     * One schedule row. `join` is the authoritative StudentJoinState
     * (ownership + strict Active lifecycle + visibility setting + the ONE
     * configured time-window calculation) — the blade renders only what
     * it released, never meeting->join_url.
     *
     * @param  array{booking: Booking, join: StudentJoinState, today: bool}  $row
     * @return array<string, mixed>
     */
    private function lessonRow(User $student, array $row): array
    {
        $booking = $row['booking'];
        $timezone = UserTimezoneResolver::resolve($student);

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'subject' => $booking->meta['subject'] ?? $booking->type?->name ?? 'Lesson',
            'instructor' => $booking->instructor?->name ?? 'Instructor to be assigned',
            'type' => $booking->type?->name ?? 'Class',
            'starts_at' => $booking->starts_at->timezone($timezone),
            'ends_at' => $booking->ends_at?->timezone($timezone),
            'today' => $row['today'],
            'booking' => $booking,
            'join' => $row['join'],
        ];
    }

    /** @return array{pending: int, overdue: int, items: array<int, array<string, mixed>>} */
    private function homework(User $student): array
    {
        $stats = $this->homework->statsForStudent($student->id);
        $timezone = UserTimezoneResolver::resolve($student);

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
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->first(['reviewed_at', 'summary', 'progress_percent']);

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
            'last_review_progress_percent' => $review?->progress_percent,
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
            'low_balance' => $wallet->currency?->low_balance_threshold_minor !== null
                && $wallet->available_balance_minor < $wallet->currency->low_balance_threshold_minor,
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
