<?php

declare(strict_types=1);

namespace App\Ai\Evaluation;

use App\Ai\Evaluation\Contracts\AiFeedbackRecorderInterface;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use App\Models\AiFeedbackEvent;
use App\Models\AiRun;

/**
 * The only writer of ai_feedback_events.
 *
 * Reads the feature and prompt version from the RUN rather than
 * accepting them from the caller: a verdict must be attributable to the
 * exact prompt that produced the output, and a caller passing its own
 * idea of which prompt was used is how that attribution quietly goes
 * wrong after a version bump.
 */
final class AiFeedbackRecorder implements AiFeedbackRecorderInterface
{
    public function record(
        ?string $aiRunId,
        AiFeedbackAction $action,
        ?AiFeedbackReason $reason = null,
        ?int $actorId = null,
    ): ?AiFeedbackEvent {
        if ($aiRunId === null) {
            return null;
        }

        $run = AiRun::query()->find($aiRunId);

        if ($run === null) {
            return null;
        }

        return AiFeedbackEvent::query()->updateOrCreate(
            ['ai_run_id' => $run->getKey(), 'actor_id' => $actorId],
            [
                'feature_key' => $run->getRawOriginal('feature_key'),
                'prompt_key' => $run->prompt_key,
                'prompt_version' => $run->prompt_version,
                'action' => $action,
                // Cleared on a positive verdict: "helpful, because
                // inaccurate" is a contradiction that would corrupt any
                // count of why outputs fail.
                'reason_code' => $action->isPositive() ? null : $reason,
            ],
        );
    }
}
