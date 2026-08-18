<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons\Summaries\Concerns;

use App\Enums\LearningPlanStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\AcademicCategory;
use App\Models\Lesson;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

trait BuildsLessonSummaryFixtures
{
    protected function enableLessonSummaries(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->lesson_summary_enabled = true;
        $settings->save();
    }

    protected function useFakedOpenAi(array $payload): void
    {
        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 260],
        ], 200, ['x-request-id' => 'req_p3'])]);
    }

    /** @return array<string, mixed> */
    protected function validSummaryPayload(array $overrides = []): array
    {
        return array_replace([
            'lesson_summary' => 'The lesson introduced quadratic equations and worked through factorisation on several practice examples together.',
            'topics_covered' => ['Factorising quadratic expressions'],
            'strengths_observed' => ['Worked confidently through the factorisation steps.'],
            'practice_recommendations' => ['Practise factorising expressions with a leading coefficient.'],
            'next_focus' => ['Solving quadratics using the formula.'],
            'confidence' => 0.55,
            'requires_instructor_review' => true,
        ], $overrides);
    }

    protected function instructor(string $firstName = 'Priya', string $lastName = 'Nair'): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']));

        return $user->fresh();
    }

    protected function student(string $firstName = 'Mira', string $lastName = 'Kowalski'): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        return $user->fresh();
    }

    /** A completed lesson with a finalized Completed outcome and an instructor note. */
    protected function completedLesson(
        User $instructor,
        User $student,
        ?string $completionNotes = 'Covered factorisation of quadratics. She worked through the examples confidently.',
        array $overrides = [],
    ): Lesson {
        return Lesson::factory()->create(array_replace([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'completion_notes' => $completionNotes,
        ], $overrides));
    }

    protected function activePlanFor(User $instructor, User $student, string $focus = 'Building confidence with algebra'): StudentLearningPlan
    {
        // A plan requires a goal, and a goal requires a subject —
        // both FKs, neither nullable.
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Improve algebra',
            'type' => 'academic',
            'status' => 'active',
            'created_by' => $instructor->id,
            'updated_by' => $instructor->id,
        ]);

        return StudentLearningPlan::query()->create([
            'learning_goal_id' => $goal->id,
            'subject_id' => $subject->id,
            'student_user_id' => $student->id,
            'primary_instructor_user_id' => $instructor->id,
            'title' => 'Algebra plan',
            'current_focus' => $focus,
            'status' => LearningPlanStatus::Active,
            'created_by' => $instructor->id,
            'updated_by' => $instructor->id,
        ]);
    }
}
