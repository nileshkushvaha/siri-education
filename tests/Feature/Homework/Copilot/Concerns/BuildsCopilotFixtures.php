<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Copilot\Concerns;

use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

trait BuildsCopilotFixtures
{
    protected function enableHomeworkCopilot(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->homework_assistant_enabled = true;
        $settings->save();
    }

    /** Switches onto the real OpenAI adapter with a faked transport. */
    protected function useFakedOpenAi(array $payload): void
    {
        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 350],
        ], 200, ['x-request-id' => 'req_p2'])]);
    }

    /** @return array<string, mixed> */
    protected function validDraftPayload(array $overrides = []): array
    {
        return array_replace([
            'summary' => 'The student answered all three parts and showed their working for the first two, but the final step is not explained.',
            'strengths' => ['Clear working shown for the first two parts.'],
            'improvements' => ['Explain the reasoning behind the final step.'],
            'suggested_feedback' => 'Nice work on this — your working for the first two parts is easy to follow. For the last step, try writing out why you chose that method.',
            'confidence' => 0.66,
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

    /** A submitted assignment awaiting the instructor's review. */
    protected function submittedAssignment(
        User $instructor,
        User $student,
        string $submissionText = 'I solved the equation by isolating x on the left side and then checking my answer.',
        array $overrides = [],
    ): HomeworkAssignment {
        return HomeworkAssignment::factory()->create(array_replace([
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            // booking_id is left to the factory: a DB CHECK requires a
            // booking or a learning plan on every assignment.
            'subject' => 'Mathematics',
            'title' => 'Quadratic equations practice',
            'description' => 'Complete questions 1 to 3 and show your working.',
            'status' => HomeworkStatus::Submitted,
            'submission_text' => $submissionText,
            'submitted_at' => now()->subHour(),
            'grade' => null,
            'feedback' => null,
        ], $overrides));
    }
}
