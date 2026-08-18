<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety;

use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Messaging\Safety\Enums\MessageSafetyCategory;
use App\Messaging\Safety\Enums\MessageSafetyFindingStatus;
use App\Messaging\Safety\Enums\MessageSafetyRiskLevel;
use App\Messaging\Safety\Enums\MessageSafetySource;
use App\Messaging\Safety\Prompts\CommunicationRiskPrompt;
use App\Messaging\Safety\Prompts\MessageModerationPrompt;
use App\Messaging\Safety\Schemas\CommunicationRiskSchema;
use App\Models\AiRun;
use App\Models\Message;
use App\Models\MessageSafetyFinding;
use App\Models\SuspiciousActivityFlag;
use App\Settings\AiSettings;
use App\Settings\ComplianceMonitoringSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Messaging\Safety\Concerns\BuildsMessageSafetyFixtures;
use Tests\TestCase;

/**
 * The finding lifecycle, and the guarantees that nothing in it ever
 * enforces anything.
 */
class MessageSafetyLifecycleTest extends TestCase
{
    use BuildsMessageSafetyFixtures, CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    private function ambiguousMessage(): Message
    {
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());

        return $this->message($conversation, $instructor, 'We can sort it out between ourselves');
    }

    // ── Registration ──────────────────────────────────────────────────

    public function test_both_prompts_and_the_schema_are_registered(): void
    {
        $prompts = app(AiPromptRegistryInterface::class);

        $this->assertTrue($prompts->has(CommunicationRiskPrompt::KEY, CommunicationRiskPrompt::VERSION));
        $this->assertTrue($prompts->has(MessageModerationPrompt::KEY, MessageModerationPrompt::VERSION));
        $this->assertTrue(app(AiSchemaRegistryInterface::class)->has(CommunicationRiskSchema::KEY));

        $risk = $prompts->get(CommunicationRiskPrompt::KEY);
        $this->assertSame(AiFeature::CommunicationModeration, $risk->feature);
        $this->assertSame(AiCapability::StructuredGeneration, $risk->capability);

        // The moderation path uses the P0 capability, unused until now.
        $this->assertSame(AiCapability::Moderation, $prompts->get(MessageModerationPrompt::KEY)->capability);
    }

    /** No enforcement instruction can be returned, because none exists in the contract. */
    public function test_the_schema_admits_no_enforcement_field(): void
    {
        $properties = array_keys((new CommunicationRiskSchema)->jsonSchema()['properties']);

        foreach (['block', 'ban', 'suspend', 'restrict', 'remove', 'delete', 'action', 'punish'] as $forbidden) {
            foreach ($properties as $property) {
                $this->assertStringNotContainsString($forbidden, $property);
            }
        }
    }

    // ── Fail closed ───────────────────────────────────────────────────

    public function test_the_capability_flag_off_prevents_any_analysis(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->communication_moderation_enabled = false;
        $settings->save();

        $this->assertNull(app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage()));
        $this->assertSame(0, AiRun::query()->count());
        $this->assertSame(0, MessageSafetyFinding::query()->count());
    }

    public function test_an_exhausted_budget_prevents_analysis(): void
    {
        $this->enableCommunicationModeration();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->save();

        $this->assertNull(app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage()));
        $this->assertSame(0, MessageSafetyFinding::query()->count());
    }

    /** Deterministic findings keep working with AI entirely off. */
    public function test_deterministic_findings_are_recorded_with_ai_disabled(): void
    {
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My number is 07700 900123', ['phone_number']);

        $finding = app(MessageSafetyServiceInterface::class)->recordDeterministicFinding($message);

        $this->assertNotNull($finding);
        $this->assertSame(MessageSafetySource::Deterministic, $finding->source_type);
    }

    // ── Intent analysis ───────────────────────────────────────────────

    public function test_a_risky_result_is_stored_as_a_probabilistic_finding(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload());

        $message = $this->ambiguousMessage();

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $finding = MessageSafetyFinding::query()->sole();

        $this->assertSame(MessageSafetySource::AiIntent, $finding->source_type);
        $this->assertTrue($finding->source_type->isProbabilistic());
        $this->assertSame(MessageSafetyCategory::ContactSharing, $finding->category);
        $this->assertSame(MessageSafetyRiskLevel::Medium, $finding->risk_level);
        $this->assertSame(0.62, $finding->confidence);
        $this->assertSame(MessageSafetyFindingStatus::Open, $finding->status);
        $this->assertNotNull($finding->ai_run_id);
    }

    /**
     * The common and desirable outcome: analysed, found ordinary, and
     * removed — an innocent message must not accumulate a permanent
     * safety record because a phrase gate once looked at it.
     */
    public function test_a_clean_result_leaves_no_finding_behind(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload([
            'category' => 'none',
            'risk_level' => 'low',
            'reason' => 'The message discusses rescheduling and nothing else.',
        ]));

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage());

        $this->assertSame(0, MessageSafetyFinding::query()->count());
        // The run is still recorded — it cost money and must be visible.
        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->sole()->status);
    }

    public function test_a_provider_failure_leaves_no_finding_behind(): void
    {
        $this->enableCommunicationModeration();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage());

        // No evidence means no accusation: a failed analysis must not
        // leave a placeholder suspicion against a real person.
        $this->assertSame(0, MessageSafetyFinding::query()->count());
    }

    // ── Moderation on report ──────────────────────────────────────────

    public function test_moderation_records_a_finding_only_when_the_classifier_flags(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiModeration(flagged: true, categories: ['harassment'], score: 0.91);

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'Something abusive.');

        app(MessageSafetyServiceInterface::class)->requestModeration($message);

        $finding = MessageSafetyFinding::query()->sole();

        $this->assertSame(MessageSafetySource::AiModeration, $finding->source_type);
        $this->assertSame(MessageSafetyCategory::UnsafeContent, $finding->category);
        $this->assertSame(MessageSafetyRiskLevel::High, $finding->risk_level);
        $this->assertSame(['harassment'], $finding->detected_patterns);
    }

    public function test_an_unflagged_moderation_result_leaves_no_finding(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiModeration(flagged: false);

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());

        app(MessageSafetyServiceInterface::class)->requestModeration(
            $this->message($conversation, $instructor, 'A perfectly ordinary message.'),
        );

        $this->assertSame(0, MessageSafetyFinding::query()->count());
    }

    // ── Nothing is ever enforced ──────────────────────────────────────

    public function test_a_finding_changes_nothing_about_the_message_conversation_or_user(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload());

        $student = $this->student();
        $instructor = $this->instructor();
        $conversation = $this->conversation($student, $instructor);
        $message = $this->message($conversation, $instructor, 'We can sort it out between ourselves');

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $message->refresh();
        $conversation->refresh();

        // The message is delivered, unaltered, in an open conversation,
        // and its sender's account is untouched.
        $this->assertSame('We can sort it out between ourselves', $message->body);
        $this->assertSame(ConversationStatus::Active, $conversation->status);
        $this->assertSame($instructor->status, $instructor->fresh()->status);
    }

    // ── Human review and escalation ───────────────────────────────────

    public function test_confirming_a_finding_records_the_reviewer_and_is_audited(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload());

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage());

        $reviewer = $this->complianceAdmin();
        $finding = MessageSafetyFinding::query()->sole();

        $confirmed = app(MessageSafetyServiceInterface::class)->confirm($finding, $reviewer, 'Clear off-platform proposal.');

        $this->assertSame(MessageSafetyFindingStatus::Confirmed, $confirmed->status);
        $this->assertSame($reviewer->id, $confirmed->reviewed_by);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'message_safety',
            'event' => 'message_safety_finding_confirmed',
        ]);
    }

    /** A model's opinion can never open a compliance flag on its own. */
    public function test_an_unconfirmed_finding_never_reaches_the_compliance_queue(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload());

        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_confirmed_message_risks_threshold = 1;
        $settings->save();

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($this->ambiguousMessage());

        $this->assertSame(1, MessageSafetyFinding::query()->count());
        $this->assertSame(0, SuspiciousActivityFlag::query()->count());
    }

    /** A PATTERN of human-confirmed findings does escalate — through the existing pipeline. */
    public function test_confirmed_findings_escalate_into_the_existing_compliance_queue(): void
    {
        $this->enableCommunicationModeration();

        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_confirmed_message_risks_enabled = true;
        $settings->repeated_confirmed_message_risks_threshold = 2;
        $settings->save();

        $reviewer = $this->complianceAdmin();
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $service = app(MessageSafetyServiceInterface::class);

        foreach (['My email is a@example.com', 'Call me on 07700 900123'] as $index => $body) {
            $message = $this->message($conversation, $instructor, $body, ['email_address']);
            $finding = $service->recordDeterministicFinding($message);
            $service->confirm($finding, $reviewer);
        }

        $flag = SuspiciousActivityFlag::query()->sole();

        $this->assertSame(SuspiciousActivityRuleCode::RepeatedConfirmedMessageRisks, $flag->rule_code);
        $this->assertSame($instructor->id, $flag->subject_id);
        // Counts only — the compliance pipeline's evidence contract
        // forbids narrative, and a model's reason stays on the finding.
        $this->assertSame(2, $flag->evidence['confirmed_finding_count']);
        $this->assertArrayNotHasKey('reason', $flag->evidence);
    }

    public function test_dismissing_a_finding_never_escalates(): void
    {
        $this->enableCommunicationModeration();

        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_confirmed_message_risks_threshold = 1;
        $settings->save();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My email is a@example.com', ['email_address']);

        $service = app(MessageSafetyServiceInterface::class);
        $finding = $service->recordDeterministicFinding($message);
        $service->dismiss($finding, $this->complianceAdmin(), 'Sharing their own school address, harmless.');

        $this->assertSame(MessageSafetyFindingStatus::Dismissed, $finding->fresh()->status);
        $this->assertSame(0, SuspiciousActivityFlag::query()->count());
    }

    /**
     * Reviewed findings are compliance history and are permanent;
     * unreviewed ones are removable precisely so an innocent message
     * does not keep a suspicion on file. Both halves matter.
     */
    public function test_a_reviewed_finding_can_never_be_deleted(): void
    {
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My email is a@example.com', ['email_address']);

        $service = app(MessageSafetyServiceInterface::class);
        $finding = $service->recordDeterministicFinding($message);
        $service->confirm($finding, $this->complianceAdmin());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $finding->fresh()->delete();
    }

    public function test_an_unreviewed_finding_can_be_discarded(): void
    {
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My email is a@example.com', ['email_address']);

        $service = app(MessageSafetyServiceInterface::class);
        $finding = $service->recordDeterministicFinding($message);

        $service->discardPending($finding);

        $this->assertSame(0, MessageSafetyFinding::query()->count());
    }

    /** discardPending must refuse to erase a decision a person already made. */
    public function test_discarding_never_erases_a_reviewed_finding(): void
    {
        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My email is a@example.com', ['email_address']);

        $service = app(MessageSafetyServiceInterface::class);
        $finding = $service->recordDeterministicFinding($message);
        $service->confirm($finding, $this->complianceAdmin());

        $service->discardPending($finding->fresh());

        $this->assertSame(1, MessageSafetyFinding::query()->count());
        $this->assertSame(MessageSafetyFindingStatus::Confirmed, $finding->fresh()->status);
    }
}
