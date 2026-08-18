<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety\Concerns;

use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Services\MessagingService;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait BuildsMessageSafetyFixtures
{
    protected function enableCommunicationModeration(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->communication_moderation_enabled = true;
        $settings->save();
    }

    /** @param array<string, mixed> $payload */
    protected function useFakedOpenAiCompletion(array $payload): void
    {
        $this->useOpenAiProvider();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 60],
        ], 200, ['x-request-id' => 'req_p4'])]);
    }

    /** The moderations endpoint has its own response shape. */
    protected function useFakedOpenAiModeration(bool $flagged, array $categories = [], float $score = 0.0): void
    {
        $this->useOpenAiProvider();

        Http::fake(['api.openai.com/*' => Http::response([
            'results' => [[
                'flagged' => $flagged,
                'categories' => array_fill_keys($categories, true) + ['violence' => false],
                'category_scores' => $categories === [] ? ['violence' => 0.0] : array_fill_keys($categories, $score),
            ]],
        ], 200, ['x-request-id' => 'req_p4_mod'])]);
    }

    private function useOpenAiProvider(): void
    {
        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();
    }

    /** @return array<string, mixed> */
    protected function riskPayload(array $overrides = []): array
    {
        return array_replace([
            'category' => 'contact_sharing',
            'risk_level' => 'medium',
            'reason' => 'The message proposes continuing the conversation on another service.',
            'confidence' => 0.62,
            'requires_review' => true,
        ], $overrides);
    }

    /**
     * A conversation the messaging domain considers eligible for
     * sending, built through the domain's own service and the existing
     * shared fixtures — so send-path tests exercise the real
     * eligibility rules rather than a fabricated row.
     */
    protected function eligibleConversation(User $student, User $instructor): Conversation
    {
        $this->confirmedPaidBooking($student, $instructor);

        return app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
    }

    protected function namedInstructor(string $firstName = 'Priya', string $lastName = 'Nair'): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']));

        return $user->fresh();
    }

    protected function namedStudent(string $firstName = 'Mira', string $lastName = 'Kowalski'): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        return $user->fresh();
    }

    /** @param list<string> $permissions */
    protected function complianceAdmin(array $permissions = ['ViewAny:SuspiciousActivityFlag', 'View:SuspiciousActivityFlag', 'Resolve:SuspiciousActivityFlag']): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin->fresh();
    }

    protected function conversation(User $student, User $instructor): Conversation
    {
        return Conversation::query()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            // context_type/context_id are required and unique per
            // participant pair; the specific context is irrelevant to
            // safety analysis, which never reads it.
            'context_type' => 'booking',
            'context_id' => (string) Str::uuid(),
            'status' => ConversationStatus::Active,
            'opened_by' => $student->id,
            'last_message_at' => now(),
        ]);
    }

    /** A message written directly, bypassing eligibility rules irrelevant to safety analysis. */
    protected function message(Conversation $conversation, User $sender, string $body, array $leakageFlags = []): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'sent_at' => now(),
            'flagged_leakage' => $leakageFlags !== [],
            'flagged_leakage_reasons' => $leakageFlags,
        ]);
    }
}
