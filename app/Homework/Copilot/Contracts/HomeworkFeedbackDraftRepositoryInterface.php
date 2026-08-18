<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Contracts;

use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;

interface HomeworkFeedbackDraftRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): HomeworkAiFeedbackDraft;

    /** @param array<string, mixed> $attributes */
    public function update(HomeworkAiFeedbackDraft $draft, array $attributes): HomeworkAiFeedbackDraft;

    public function find(string $id): ?HomeworkAiFeedbackDraft;

    /** A run still in flight for this submission — the duplicate-spend guard. */
    public function pendingFor(HomeworkAssignment $assignment): ?HomeworkAiFeedbackDraft;

    /** The draft the instructor should currently see, if any: newest Pending, Ready or Failed. */
    public function activeFor(HomeworkAssignment $assignment): ?HomeworkAiFeedbackDraft;
}
