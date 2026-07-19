<?php

declare(strict_types=1);

namespace App\Homework\Actions;

use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;

/**
 * Phase 24J — GAP-021: persists a new homework assignment. Context
 * validation and authorization happen in HomeworkService::assign();
 * this action only writes the already-validated state.
 */
final class AssignHomeworkAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): HomeworkAssignment
    {
        $assignment = HomeworkAssignment::create([
            ...$attributes,
            'status' => HomeworkStatus::Pending,
        ]);

        return $assignment->refresh();
    }
}
