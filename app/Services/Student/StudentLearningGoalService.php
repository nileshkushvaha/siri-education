<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
use App\Models\AcademicLevel;
use App\Models\StudentLearningGoal;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentLearningGoalService
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $student, array $data): StudentLearningGoal
    {
        return DB::transaction(function () use ($student, $data): StudentLearningGoal {
            $payload = $this->validatedPayload($data);

            /** @var StudentLearningGoal $goal */
            $goal = $student->studentLearningGoals()->create([
                ...$payload,
                'status' => LearningGoalStatus::Active,
                'created_by' => $student->id,
                'updated_by' => $student->id,
            ]);

            $this->audit->logUser(
                $student,
                'student_learning_goals',
                'student_learning_goal_created',
                'Student learning goal created.',
                $goal,
                ['goal_id' => $goal->id, 'subject_id' => $goal->subject_id, 'type' => $goal->type->value],
            );

            return $goal->load(['subject', 'academicLevel']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $student, StudentLearningGoal $goal, array $data): StudentLearningGoal
    {
        $this->ensureOwner($student, $goal);

        return DB::transaction(function () use ($student, $goal, $data): StudentLearningGoal {
            $payload = $this->validatedPayload($data);
            $goal->forceFill([...$payload, 'updated_by' => $student->id])->save();

            $this->audit->logUser(
                $student,
                'student_learning_goals',
                'student_learning_goal_updated',
                'Student learning goal updated.',
                $goal,
                ['goal_id' => $goal->id, 'subject_id' => $goal->subject_id, 'type' => $goal->type->value],
            );

            return $goal->refresh()->load(['subject', 'academicLevel']);
        });
    }

    public function complete(User $student, StudentLearningGoal $goal): StudentLearningGoal
    {
        $this->ensureOwner($student, $goal);

        return DB::transaction(function () use ($student, $goal): StudentLearningGoal {
            $goal->forceFill([
                'status' => LearningGoalStatus::Completed,
                'completed_at' => now(),
                'archived_at' => null,
                'updated_by' => $student->id,
            ])->save();

            $this->audit->logUser(
                $student,
                'student_learning_goals',
                'student_learning_goal_completed',
                'Student learning goal completed.',
                $goal,
                ['goal_id' => $goal->id],
            );

            return $goal->refresh();
        });
    }

    public function archive(User $student, StudentLearningGoal $goal): StudentLearningGoal
    {
        $this->ensureOwner($student, $goal);

        return DB::transaction(function () use ($student, $goal): StudentLearningGoal {
            $goal->forceFill([
                'status' => LearningGoalStatus::Archived,
                'archived_at' => now(),
                'updated_by' => $student->id,
            ])->save();

            $this->audit->logUser(
                $student,
                'student_learning_goals',
                'student_learning_goal_archived',
                'Student learning goal archived.',
                $goal,
                ['goal_id' => $goal->id],
            );

            return $goal->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedPayload(array $data): array
    {
        $type = LearningGoalType::tryFrom((string) ($data['type'] ?? ''));

        if (! $type) {
            throw ValidationException::withMessages(['type' => 'Choose a valid learning goal type.']);
        }

        $subjectId = (string) ($data['subject_id'] ?? '');
        if ($subjectId === '' || ! Subject::availableForAssignment()->whereKey($subjectId)->exists()) {
            throw ValidationException::withMessages(['subject_id' => 'Choose an active subject from the catalog.']);
        }

        $academicLevelId = $data['academic_level_id'] ?? null;
        if (($academicLevelId === null || $academicLevelId === '') && $type->requiresAcademicLevel()) {
            throw ValidationException::withMessages(['academic_level_id' => 'Academic and exam preparation goals need an academic level.']);
        }

        if ($academicLevelId !== null && $academicLevelId !== '' && ! AcademicLevel::availableForAssignment()->whereKey($academicLevelId)->exists()) {
            throw ValidationException::withMessages(['academic_level_id' => 'Choose an active academic level.']);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Add a clear goal title.']);
        }

        $targetDate = $data['target_date'] ?? null;
        if ($targetDate !== null && $targetDate !== '' && now()->startOfDay()->greaterThanOrEqualTo(CarbonImmutable::parse((string) $targetDate)->startOfDay())) {
            throw ValidationException::withMessages(['target_date' => 'Choose a future target date.']);
        }

        return [
            'subject_id' => $subjectId,
            'academic_level_id' => $academicLevelId ?: null,
            'title' => $title,
            'type' => $type,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'target_date' => $targetDate ?: null,
            'priority' => filled($data['priority'] ?? null) ? (int) $data['priority'] : null,
        ];
    }

    private function ensureOwner(User $student, StudentLearningGoal $goal): void
    {
        if ($goal->user_id !== $student->id && ! $student->can('update', $goal)) {
            throw new AuthorizationException;
        }
    }
}
