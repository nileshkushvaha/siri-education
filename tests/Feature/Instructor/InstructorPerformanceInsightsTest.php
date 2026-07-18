<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorAnalyticsPeriod;
use App\Lessons\Enums\LessonStatus;
use App\Livewire\Frontend\Instructor\AnalyticsOverview;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorPerformanceInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_instructor_sees_own_advanced_insights(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.analytics'))->assertOk();

        $response->assertSee('Teaching Trends');
        $response->assertSee('Student Activity');
    }

    public function test_student_cannot_access_advanced_insights_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.analytics'))
            ->assertForbidden();
    }

    public function test_instructor_cannot_see_another_instructors_insight_data(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherStudent->assignRole('student');
        $this->makeLesson($otherInstructor, $otherStudent, ['status' => LessonStatus::Completed]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('insights', fn ($insights) => $insights->lessons->completedCurrent === 0
                && $insights->students->activeStudents === 0);
    }

    // ── Lesson trends ─────────────────────────────────────────────────

    public function test_lesson_trend_current_period_calculation(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Cancelled, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('insights', fn ($insights) => $insights->lessons->completedCurrent === 2
                && $insights->lessons->cancelledCurrent === 1
                && $insights->lessons->hasComparison === true);
    }

    public function test_lesson_trend_comparison_period_calculation(): void
    {
        // Current Last7Days window: 1 completed lesson.
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(1), 'ends_at' => now()->subDays(1)->addHour()]);
        // Previous 7-day window (8-14 days ago): 2 completed lessons.
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(9)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(11), 'ends_at' => now()->subDays(11)->addHour()]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->call('setPeriod', InstructorAnalyticsPeriod::Last7Days->value)
            ->assertViewHas('insights', function ($insights) {
                // 1 vs 2 = -50% change.
                return $insights->lessons->completedCurrent === 1
                    && $insights->lessons->completedPrevious === 2
                    && $insights->lessons->completedChangePercent === -50.0;
            });
    }

    public function test_all_time_period_has_no_comparison(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->call('setPeriod', InstructorAnalyticsPeriod::AllTime->value)
            ->assertViewHas('insights', fn ($insights) => $insights->lessons->hasComparison === false)
            ->assertSee('No comparison available for All time.');
    }

    // ── Quality ───────────────────────────────────────────────────────

    public function test_quality_trend_review_count_matches_seeded_reviews(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);
        $this->makeReview($this->instructor, $this->student, ['overall_rating' => 5, 'submitted_at' => now()->subDays(2)]);
        $this->makeReview($this->instructor, $this->student, ['overall_rating' => 3, 'submitted_at' => now()->subDays(3)]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('insights', fn ($insights) => $insights->quality->reviewCountCurrent === 2
                && $insights->quality->averageRatingCurrent === 4.0);
    }

    public function test_quality_trend_never_mismatches_the_authoritative_rating_aggregate(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);
        $this->makeReview($this->instructor, $this->student, ['overall_rating' => 5, 'submitted_at' => now()->subDays(2)]);
        $this->makeReview($this->instructor, $this->student, ['overall_rating' => 4, 'submitted_at' => now()->subDays(3)]);
        $this->makeReview($this->instructor, $this->student, ['overall_rating' => 3, 'submitted_at' => now()->subDays(4)]);

        // Reconcile the authoritative aggregate the same way the real
        // review pipeline does, so this is a genuine cross-check against
        // production logic, not a hand-computed expectation.
        $aggregateService = app(InstructorRatingAggregateServiceInterface::class);
        $authoritative = $aggregateService->rebuildForInstructor($this->instructor->id);

        $insightsService = app(InstructorQualityInsightsServiceInterface::class)->insightsFor($this->instructor->fresh());

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->call('setPeriod', InstructorAnalyticsPeriod::AllTime->value)
            ->assertViewHas('insights', function ($insights) use ($authoritative, $insightsService) {
                // AllTime is the one period where the Phase 23P period
                // slice and the Phase 17K all-time aggregate must agree
                // exactly — same reviews, same eligibility predicate.
                return $insights->quality->reviewCountCurrent === $authoritative->eligible_review_count
                    && $insights->quality->reviewCountCurrent === $insightsService->ratingSummary->reviewCount
                    && $insights->quality->averageRatingCurrent === $insightsService->ratingSummary->averageRating;
            });
    }

    // ── Student engagement ────────────────────────────────────────────

    public function test_student_engagement_is_scoped_to_the_instructor(): void
    {
        $activeStudent = $this->student;
        $this->makeLesson($this->instructor, $activeStudent, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);

        $withoutUpcoming = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $withoutUpcoming->assignRole('student');
        $this->makeLesson($this->instructor, $withoutUpcoming, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(10)->addHour()]);

        $withUpcoming = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $withUpcoming->assignRole('student');
        $this->makeLesson($this->instructor, $withUpcoming, ['status' => LessonStatus::Scheduled, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('insights', fn ($insights) => $insights->students->studentsWithoutUpcomingLesson === 2);
    }

    public function test_student_engagement_never_exposes_a_student_name_or_id(): void
    {
        $namedStudent = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Very Unique Student Name Zzyzx']);
        $namedStudent->assignRole('student');
        $this->makeLesson($this->instructor, $namedStudent, ['status' => LessonStatus::Completed]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.analytics'))->assertOk();

        $response->assertDontSee('Very Unique Student Name Zzyzx');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeLesson(User $instructor, User $student, array $overrides = []): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'starts_at' => $overrides['starts_at'] ?? now()->addDay(),
            'ends_at' => $overrides['ends_at'] ?? now()->addDay()->addHour(),
        ]);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeReview(User $instructor, User $student, array $overrides = []): LessonReview
    {
        return LessonReview::factory()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'status' => StudentReviewStatus::Published,
            ...$overrides,
        ]);
    }
}
