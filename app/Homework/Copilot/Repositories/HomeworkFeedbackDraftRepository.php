<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Repositories;

use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftRepositoryInterface;
use App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;

final class HomeworkFeedbackDraftRepository implements HomeworkFeedbackDraftRepositoryInterface
{
    public function create(array $attributes): HomeworkAiFeedbackDraft
    {
        return HomeworkAiFeedbackDraft::query()->create($attributes);
    }

    public function update(HomeworkAiFeedbackDraft $draft, array $attributes): HomeworkAiFeedbackDraft
    {
        $draft->fill($attributes)->save();

        return $draft;
    }

    public function find(string $id): ?HomeworkAiFeedbackDraft
    {
        return HomeworkAiFeedbackDraft::query()->find($id);
    }

    public function pendingFor(HomeworkAssignment $assignment): ?HomeworkAiFeedbackDraft
    {
        return HomeworkAiFeedbackDraft::query()
            ->where('homework_assignment_id', $assignment->getKey())
            ->where('status', HomeworkFeedbackDraftStatus::Pending)
            ->latest('created_at')
            ->first();
    }

    public function activeFor(HomeworkAssignment $assignment): ?HomeworkAiFeedbackDraft
    {
        return HomeworkAiFeedbackDraft::query()
            ->where('homework_assignment_id', $assignment->getKey())
            // Failed is included so a run that produced nothing tells
            // the instructor why, instead of the draft silently
            // vanishing and the button simply reappearing. Used and
            // Discarded are excluded — those are finished business.
            ->whereIn('status', [
                HomeworkFeedbackDraftStatus::Pending,
                HomeworkFeedbackDraftStatus::Ready,
                HomeworkFeedbackDraftStatus::Failed,
            ])
            ->latest('created_at')
            ->first();
    }
}
