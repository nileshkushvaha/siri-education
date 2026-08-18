<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons\Summaries;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\Enums\LessonSummaryStatus;
use App\Livewire\Frontend\Instructor\LessonFeedbackManager;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Models\User;
use App\Policies\LessonAiSummaryPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Lessons\Summaries\Concerns\BuildsLessonSummaryFixtures;
use Tests\TestCase;

/**
 * Who may generate, see and approve a lesson summary.
 *
 * The student case is the deliberate break from LessonPolicy: they are
 * a participant in the lesson, but a summary is the tutor's
 * professional record and its draft is unreviewed model output.
 */
class LessonSummaryAuthorizationTest extends TestCase
{
    use BuildsLessonSummaryFixtures, RefreshDatabase;

    private function readySummary(User $instructor, ?Lesson $lesson = null): LessonAiSummary
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $lesson ??= $this->completedLesson($instructor, $this->student());

        return app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();
    }

    // ── The lesson's instructor ───────────────────────────────────────

    public function test_the_lessons_instructor_may_generate_view_and_act(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $this->actingAs($instructor);

        $this->assertTrue($instructor->can('generate', [LessonAiSummary::class, $summary->lesson]));
        $this->assertTrue($instructor->can('view', $summary));
        $this->assertTrue($instructor->can('act', $summary));
    }

    public function test_generation_is_refused_before_the_outcome_is_completed(): void
    {
        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student(), overrides: [
            'outcome' => LessonOutcome::TechnicalIssue,
        ]);

        $this->actingAs($instructor);

        $this->assertFalse($instructor->can('generate', [LessonAiSummary::class, $lesson]));
    }

    // ── Everyone else ─────────────────────────────────────────────────

    public function test_the_student_can_never_see_the_summary_of_their_own_lesson(): void
    {
        $instructor = $this->instructor();
        $student = $this->student();
        $lesson = $this->completedLesson($instructor, $student);
        $summary = $this->readySummary($instructor, $lesson);

        $this->actingAs($student);

        $this->assertFalse($student->can('view', $summary));
        $this->assertFalse($student->can('act', $summary));
        $this->assertFalse($student->can('generate', [LessonAiSummary::class, $lesson]));
    }

    public function test_another_instructor_cannot_reach_the_summary(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $other = $this->instructor('Other', 'Teacher');

        $this->actingAs($other);

        $this->assertFalse($other->can('view', $summary));
        $this->assertFalse($other->can('act', $summary));
        $this->assertFalse($other->can('generate', [LessonAiSummary::class, $summary->lesson]));
    }

    /** Staff read summaries exactly as they read the lesson — no wider, no narrower. */
    public function test_staff_with_lesson_visibility_may_view_but_never_approve(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        $manager->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Lesson', 'guard_name' => 'web']));
        $manager = $manager->fresh();

        $this->actingAs($manager);

        $this->assertTrue($manager->can('view', $summary));
        // Writing up a lesson is the teaching professional's job.
        $this->assertFalse($manager->can('act', $summary));
        $this->assertFalse($manager->can('generate', [LessonAiSummary::class, $summary->lesson]));
    }

    public function test_staff_without_lesson_visibility_see_nothing(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->actingAs($manager->fresh());

        $this->assertFalse($manager->fresh()->can('view', $summary));
    }

    public function test_the_service_refuses_a_non_owning_instructor_directly(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $this->expectException(AuthorizationException::class);

        app(LessonSummaryServiceInterface::class)
            ->approve($summary, $this->instructor('Other', 'Teacher'), 'Not my lesson to write up.');
    }

    // ── Read-only by construction ─────────────────────────────────────

    public function test_summaries_can_never_be_created_or_edited_by_hand(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $policy = app(LessonAiSummaryPolicy::class);

        $this->assertFalse($policy->create($instructor));
        $this->assertFalse($policy->update($instructor, $summary));
        $this->assertFalse($policy->delete($instructor, $summary));
    }

    public function test_a_summary_is_a_historical_record_and_cannot_be_deleted(): void
    {
        $summary = $this->readySummary($this->instructor());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $summary->delete();
    }

    // ── The instructor UI ─────────────────────────────────────────────

    public function test_the_instructor_can_generate_from_their_lesson_list(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $this->actingAs($instructor);

        Livewire::test(LessonFeedbackManager::class)
            ->call('generateSummary', $lesson->getKey())
            ->assertHasNoErrors();

        $this->assertSame(LessonSummaryStatus::Ready, LessonAiSummary::query()->sole()->status);
    }

    public function test_approval_through_the_component_records_the_edited_text(): void
    {
        $instructor = $this->instructor();
        $summary = $this->readySummary($instructor);

        $this->actingAs($instructor);

        Livewire::test(LessonFeedbackManager::class)
            ->call('startSummaryReview', $summary->getKey())
            ->assertSet('summaryText', $summary->lesson_summary)
            ->set('summaryText', 'My own corrected write-up of what we actually covered.')
            ->call('approveSummary');

        $summary->refresh();

        $this->assertSame(LessonSummaryStatus::Approved, $summary->status);
        $this->assertSame('My own corrected write-up of what we actually covered.', $summary->approved_summary);
    }

    public function test_another_instructor_cannot_approve_through_the_component(): void
    {
        $summary = $this->readySummary($this->instructor());

        $this->actingAs($this->instructor('Other', 'Teacher'));

        Livewire::test(LessonFeedbackManager::class)
            ->call('startSummaryReview', $summary->getKey())
            ->assertForbidden();
    }

    public function test_the_lesson_screen_still_works_when_ai_is_unavailable(): void
    {
        // AI entirely off.
        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $this->actingAs($instructor);

        Livewire::test(LessonFeedbackManager::class)
            ->call('generateSummary', $lesson->getKey())
            ->assertHasErrors('aiSummary')
            ->assertOk();
    }
}
