<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorAnalyticsPeriod;
use App\Lessons\Enums\LessonStatus;
use App\Livewire\Frontend\Instructor\AnalyticsOverview;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\InstructorRatingAggregate;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorAnalyticsTest extends TestCase
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

    public function test_instructor_sees_own_analytics_page(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.analytics'))
            ->assertOk()
            ->assertSeeLivewire(AnalyticsOverview::class);
    }

    public function test_student_cannot_access_instructor_analytics(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.analytics'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_analytics_page(): void
    {
        $this->get(route('dashboard.instructor.analytics'))->assertRedirect(route('auth.login'));
    }

    // ── Ownership ─────────────────────────────────────────────────────

    public function test_instructor_cannot_see_another_instructors_analytics(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherStudent->assignRole('student');
        $this->makeLesson($otherInstructor, $otherStudent, ['status' => LessonStatus::Completed]);

        // The other instructor has 1 student/lesson; this instructor has none.
        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('data', fn ($data) => $data->students['total'] === 0 && $data->lessons['total'] === 0);
    }

    // ── Privacy ───────────────────────────────────────────────────────

    public function test_analytics_page_never_exposes_student_email(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email' => 'analytics-private@example.test']);
        $student->assignRole('student');
        $this->makeLesson($this->instructor, $student, ['status' => LessonStatus::Completed]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.analytics'))->assertOk();

        $response->assertDontSee('analytics-private@example.test');
    }

    // ── Metrics match seeded data ────────────────────────────────────

    public function test_lesson_metrics_match_seeded_data(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Cancelled, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::StudentNoShow, 'starts_at' => now()->subDays(1), 'ends_at' => now()->subDays(1)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Scheduled, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

        // The scheduled lesson starts 3 days from now — outside the
        // backward-looking Last30Days historical window entirely, so it
        // is excluded from `total` and only ever surfaces via the
        // separate, period-independent `upcoming` count.
        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('data', function ($data) {
                return $data->lessons['total'] === 4
                    && $data->lessons['completed'] === 2
                    && $data->lessons['cancelled'] === 1
                    && $data->lessons['no_show'] === 1
                    && $data->lessons['upcoming'] === 1;
            });
    }

    public function test_student_metrics_match_seeded_data(): void
    {
        $activeStudent = $this->student;
        $this->makeLesson($this->instructor, $activeStudent, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);

        $staleStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $staleStudent->assignRole('student');
        $this->makeLesson($this->instructor, $staleStudent, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(200), 'ends_at' => now()->subDays(200)->addHour()]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('data', function ($data) {
                return $data->students['total'] === 2
                    && $data->students['active'] === 1
                    && $data->students['new_this_period'] === 1;
            });
    }

    public function test_homework_metrics_match_seeded_data(): void
    {
        $booking = Booking::factory()->confirmed()->create(['instructor_id' => $this->instructor->id, 'student_id' => $this->student->id]);

        HomeworkAssignment::factory()->create(['teacher_id' => $this->instructor->id, 'student_id' => $this->student->id, 'booking_id' => $booking->id]);
        HomeworkAssignment::factory()->submitted()->create(['teacher_id' => $this->instructor->id, 'student_id' => $this->student->id, 'booking_id' => $booking->id]);
        HomeworkAssignment::factory()->graded()->create(['teacher_id' => $this->instructor->id, 'student_id' => $this->student->id, 'booking_id' => $booking->id]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertViewHas('data', function ($data) {
                return $data->homework['assigned'] === 3
                    && $data->homework['submitted'] === 2
                    && $data->homework['graded'] === 1;
            });
    }

    public function test_quality_metrics_match_the_existing_rating_aggregate(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);
        InstructorRatingAggregate::factory()->create([
            'instructor_id' => $this->instructor->id,
            'eligible_review_count' => 10,
            'overall_rating_sum' => 48,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.analytics'))->assertOk();

        $response->assertSee('4.8');
        $response->assertSee('10 reviews');
    }

    // ── Period filter ─────────────────────────────────────────────────

    public function test_period_filter_changes_which_lessons_are_counted(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(60)->addHour()]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->call('setPeriod', InstructorAnalyticsPeriod::Last7Days->value)
            ->assertViewHas('data', fn ($data) => $data->lessons['completed'] === 1)
            ->call('setPeriod', InstructorAnalyticsPeriod::AllTime->value)
            ->assertViewHas('data', fn ($data) => $data->lessons['completed'] === 2);
    }

    public function test_invalid_period_value_is_ignored(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->call('setPeriod', 'not-a-real-period')
            ->assertSet('period', InstructorAnalyticsPeriod::Last30Days->value);
    }

    // ── Empty states ──────────────────────────────────────────────────

    public function test_empty_state_is_shown_with_no_lessons(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertSee('Your analytics will appear after you complete your first lesson.');
    }

    public function test_no_reviews_empty_state_is_shown_when_students_exist_but_no_reviews(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        Livewire::actingAs($this->instructor)
            ->test(AnalyticsOverview::class)
            ->assertSee('Reviews will appear after students complete lessons.');
    }

    // ── Query safety ──────────────────────────────────────────────────

    public function test_analytics_page_query_count_is_bounded_as_lessons_grow(): void
    {
        $this->seedLessons(5);
        $initial = $this->queryCountForAnalyticsPage();

        $this->seedLessons(50);
        $grown = $this->queryCountForAnalyticsPage();

        $this->assertLessThanOrEqual($initial + 2, $grown, 'Analytics page queries must stay bounded (aggregate queries only), not scale with lesson count.');
    }

    private function seedLessons(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $this->makeLesson($this->instructor, $this->student, [
                'status' => LessonStatus::Completed,
                'starts_at' => now()->subDays(1),
                'ends_at' => now()->subDays(1)->addHour(),
            ]);
        }
    }

    private function queryCountForAnalyticsPage(): int
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($this->instructor)->get(route('dashboard.instructor.analytics'))->assertOk();

        return $queries;
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
}
