<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluation;

use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Evaluation\Contracts\AiFeedbackRecorderInterface;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use App\Models\AiFeedbackEvent;
use App\Models\AiRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The reusable evaluation hook: what a reviewer's verdict records, and
 * — just as importantly — what it must never record.
 */
class AiFeedbackRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function aiRun(array $overrides = []): AiRun
    {
        return AiRun::query()->create(array_replace([
            'feature_key' => AiFeature::QualityInsights->value,
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'prompt_key' => 'quality_insight',
            'prompt_version' => 'v1',
            'status' => AiRunStatus::Succeeded->value,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'estimated_cost' => 0.001,
        ], $overrides));
    }

    public function test_a_verdict_is_attributed_to_the_run_and_its_prompt_version(): void
    {
        $run = $this->aiRun();
        $actor = User::factory()->create();

        $event = app(AiFeedbackRecorderInterface::class)->record(
            aiRunId: $run->getKey(),
            action: AiFeedbackAction::Helpful,
            actorId: $actor->id,
        );

        $this->assertNotNull($event);
        $this->assertSame(AiFeature::QualityInsights, $event->feature_key);
        // Read from the run, never from the caller — a caller passing
        // its own idea of the prompt is how attribution silently breaks
        // after a version bump.
        $this->assertSame('quality_insight', $event->prompt_key);
        $this->assertSame('v1', $event->prompt_version);
        $this->assertSame($actor->id, $event->actor_id);
    }

    public function test_a_negative_verdict_keeps_its_reason(): void
    {
        $event = app(AiFeedbackRecorderInterface::class)->record(
            aiRunId: $this->aiRun()->getKey(),
            action: AiFeedbackAction::NotHelpful,
            reason: AiFeedbackReason::TooGeneric,
            actorId: User::factory()->create()->id,
        );

        $this->assertSame(AiFeedbackReason::TooGeneric, $event->reason_code);
    }

    /** "Helpful, because inaccurate" would corrupt any count of why outputs fail. */
    public function test_a_positive_verdict_clears_any_reason(): void
    {
        $event = app(AiFeedbackRecorderInterface::class)->record(
            aiRunId: $this->aiRun()->getKey(),
            action: AiFeedbackAction::Helpful,
            reason: AiFeedbackReason::Inaccurate,
            actorId: User::factory()->create()->id,
        );

        $this->assertNull($event->reason_code);
    }

    public function test_a_reviewer_changing_their_mind_updates_rather_than_duplicates(): void
    {
        $run = $this->aiRun();
        $actor = User::factory()->create();
        $recorder = app(AiFeedbackRecorderInterface::class);

        $recorder->record($run->getKey(), AiFeedbackAction::Helpful, actorId: $actor->id);
        $recorder->record($run->getKey(), AiFeedbackAction::NotHelpful, AiFeedbackReason::Overconfident, $actor->id);

        $this->assertSame(1, AiFeedbackEvent::query()->count());
        $this->assertSame(AiFeedbackAction::NotHelpful, AiFeedbackEvent::query()->sole()->action);
    }

    public function test_two_reviewers_may_each_record_their_own_verdict(): void
    {
        $run = $this->aiRun();
        $recorder = app(AiFeedbackRecorderInterface::class);

        $recorder->record($run->getKey(), AiFeedbackAction::Helpful, actorId: User::factory()->create()->id);
        $recorder->record($run->getKey(), AiFeedbackAction::NotHelpful, AiFeedbackReason::WrongTone, User::factory()->create()->id);

        $this->assertSame(2, AiFeedbackEvent::query()->count());
    }

    /** A blocked or failed run produced nothing to evaluate. */
    public function test_a_verdict_without_a_run_is_not_recorded(): void
    {
        $this->assertNull(app(AiFeedbackRecorderInterface::class)->record(null, AiFeedbackAction::Helpful));
        $this->assertNull(app(AiFeedbackRecorderInterface::class)->record('missing-run-id', AiFeedbackAction::Helpful));
        $this->assertSame(0, AiFeedbackEvent::query()->count());
    }

    // ── Privacy ───────────────────────────────────────────────────────

    /**
     * A verdict must be able to answer "is v2 better than v1" without
     * being able to answer "what did staff think of this student".
     */
    public function test_the_table_holds_no_subject_reference_or_free_text(): void
    {
        $columns = Schema::getColumnListing('ai_feedback_events');

        foreach (['subject', 'student', 'instructor', 'message', 'lesson', 'comment', 'note', 'body', 'content', 'text'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "Evaluation must not reference or store content: {$column}");
            }
        }

        $this->assertContains('reason_code', $columns);
    }

    public function test_the_reason_is_a_fixed_code_never_prose(): void
    {
        $event = app(AiFeedbackRecorderInterface::class)->record(
            aiRunId: $this->aiRun()->getKey(),
            action: AiFeedbackAction::NotHelpful,
            reason: AiFeedbackReason::Inaccurate,
            actorId: User::factory()->create()->id,
        );

        // Cast back to the enum, so nothing arbitrary can be stored.
        $this->assertInstanceOf(AiFeedbackReason::class, $event->reason_code);
    }
}
