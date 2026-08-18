<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Copilot;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Livewire\Frontend\Instructor\HomeworkList;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Policies\HomeworkAiFeedbackDraftPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Homework\Copilot\Concerns\BuildsCopilotFixtures;
use Tests\TestCase;

/**
 * Who may request, see and act on an AI feedback draft.
 *
 * The student case is the one that matters most: a draft is unreviewed
 * model output about their own work, and it exists so the tutor can
 * correct it before anything reaches them.
 */
class HomeworkCopilotAuthorizationTest extends TestCase
{
    use BuildsCopilotFixtures, RefreshDatabase;

    private function readyDraft(User $instructor, ?HomeworkAssignment $assignment = null): HomeworkAiFeedbackDraft
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $assignment ??= $this->submittedAssignment($instructor, $this->student());

        return app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();
    }

    // ── The assigning instructor ──────────────────────────────────────

    public function test_the_assigning_instructor_may_generate_view_and_act(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $this->actingAs($instructor);

        $this->assertTrue($instructor->can('generate', [HomeworkAiFeedbackDraft::class, $draft->assignment]));
        $this->assertTrue($instructor->can('view', $draft));
        $this->assertTrue($instructor->can('act', $draft));
    }

    // ── Everyone else ─────────────────────────────────────────────────

    public function test_the_student_can_never_see_a_draft_about_their_own_work(): void
    {
        $instructor = $this->instructor();
        $student = $this->student();
        $assignment = $this->submittedAssignment($instructor, $student);
        $draft = $this->readyDraft($instructor, $assignment);

        $this->actingAs($student);

        $this->assertFalse($student->can('view', $draft));
        $this->assertFalse($student->can('act', $draft));
        $this->assertFalse($student->can('generate', [HomeworkAiFeedbackDraft::class, $assignment]));
    }

    public function test_another_instructor_cannot_reach_the_draft(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $other = $this->instructor('Other', 'Teacher');

        $this->actingAs($other);

        $this->assertFalse($other->can('view', $draft));
        $this->assertFalse($other->can('act', $draft));
        $this->assertFalse($other->can('generate', [HomeworkAiFeedbackDraft::class, $draft->assignment]));
    }

    public function test_a_manager_has_no_homework_draft_surface(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->actingAs($manager);

        // Matches HomeworkAssignmentPolicy, which grants staff nothing:
        // homework stays between one student and their tutor.
        $this->assertFalse($manager->can('view', $draft));
    }

    public function test_the_service_refuses_a_non_assigning_instructor_directly(): void
    {
        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());
        $this->enableHomeworkCopilot();

        $this->expectException(AuthorizationException::class);

        app(HomeworkFeedbackDraftServiceInterface::class)
            ->request($assignment, $this->instructor('Other', 'Teacher'));
    }

    public function test_acting_on_someone_elses_draft_is_refused_by_the_service(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $this->expectException(AuthorizationException::class);

        app(HomeworkFeedbackDraftServiceInterface::class)
            ->markUsed($draft, $this->instructor('Other', 'Teacher'));
    }

    // ── Read-only by construction ─────────────────────────────────────

    public function test_drafts_can_never_be_created_or_edited_by_hand(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $policy = app(HomeworkAiFeedbackDraftPolicy::class);

        $this->assertFalse($policy->create($instructor));
        $this->assertFalse($policy->update($instructor, $draft));
        $this->assertFalse($policy->delete($instructor, $draft));
    }

    public function test_a_draft_is_a_historical_record_and_cannot_be_deleted(): void
    {
        $draft = $this->readyDraft($this->instructor());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $draft->delete();
    }

    // ── The instructor UI ─────────────────────────────────────────────

    public function test_the_instructor_can_generate_a_draft_from_the_review_screen(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $this->actingAs($instructor);

        Livewire::test(HomeworkList::class)
            ->call('startReview', $assignment->getKey())
            ->call('generateAiDraft', $assignment->getKey())
            ->assertHasNoErrors();

        $this->assertSame(HomeworkFeedbackDraftStatus::Ready, HomeworkAiFeedbackDraft::query()->sole()->status);
    }

    /** Using a draft fills the editor — it does not publish anything. */
    public function test_using_a_draft_populates_the_editor_only(): void
    {
        $instructor = $this->instructor();
        $draft = $this->readyDraft($instructor);

        $this->actingAs($instructor);

        Livewire::test(HomeworkList::class)
            ->call('useAiDraft', $draft->getKey())
            ->assertSet('feedbackText', $draft->suggested_feedback);

        $this->assertNull($draft->assignment->refresh()->feedback);
        $this->assertSame(HomeworkFeedbackDraftStatus::Used, $draft->refresh()->status);
    }

    public function test_another_instructor_cannot_use_a_draft_through_the_component(): void
    {
        $draft = $this->readyDraft($this->instructor());

        $this->actingAs($this->instructor('Other', 'Teacher'));

        Livewire::test(HomeworkList::class)
            ->call('useAiDraft', $draft->getKey())
            ->assertForbidden();
    }

    public function test_the_screen_still_works_when_ai_is_unavailable(): void
    {
        // AI entirely off — the review screen must not break.
        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $this->actingAs($instructor);

        Livewire::test(HomeworkList::class)
            ->call('startReview', $assignment->getKey())
            ->call('generateAiDraft', $assignment->getKey())
            ->assertHasErrors('aiDraft')
            ->assertOk();

        // And the instructor can still publish their own feedback.
        Livewire::test(HomeworkList::class)
            ->call('startReview', $assignment->getKey())
            ->set('feedbackText', 'My own feedback, written by hand.')
            ->call('submitReview');

        $this->assertSame('My own feedback, written by hand.', $assignment->refresh()->feedback);
        $this->assertSame(HomeworkStatus::Graded, $assignment->status);
    }
}
