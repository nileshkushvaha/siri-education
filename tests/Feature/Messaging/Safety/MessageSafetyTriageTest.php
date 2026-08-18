<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Messaging\Safety\Enums\MessageSafetySource;
use App\Messaging\Safety\Resolvers\CommunicationSafetyInputResolver;
use App\Messaging\Safety\Support\AmbiguousIntentDetector;
use App\Models\AiRun;
use App\Models\MessageSafetyFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Messaging\Safety\Concerns\BuildsMessageSafetyFixtures;
use Tests\TestCase;

/**
 * The triage gate and the privacy boundary — the two controls that
 * stand in for the human initiation P1-P3 had and this phase cannot.
 */
class MessageSafetyTriageTest extends TestCase
{
    use BuildsMessageSafetyFixtures, CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    // ── Layer 1 answers the obvious cases for free ────────────────────

    public function test_a_message_with_an_obvious_pattern_is_never_sent_to_a_provider(): void
    {
        $detector = app(AmbiguousIntentDetector::class);

        foreach ([
            'My email is tutor@example.com',
            'Call me on 07700 900123',
            'Here is the link https://example.com/tutor',
            'Message me on WhatsApp instead',
        ] as $body) {
            $this->assertFalse(
                $detector->warrantsAiAnalysis($body),
                "Deterministic rules already explain this; AI must not be called: {$body}",
            );
        }
    }

    public function test_ordinary_tutoring_conversation_never_reaches_a_provider(): void
    {
        $detector = app(AmbiguousIntentDetector::class);

        foreach ([
            'Great work today, see you next week.',
            'Can we move Thursday to 5pm?',
            'I have uploaded the exercises for you.',
            'Sorry I was late, the last lesson overran.',
            'You solved that directly and clearly — well done.',
        ] as $body) {
            $this->assertFalse($detector->warrantsAiAnalysis($body), "Ordinary message must not be analysed: {$body}");
        }
    }

    /** The residue no pattern can express — the only thing worth paying for. */
    public function test_genuinely_ambiguous_phrasing_is_selected_for_analysis(): void
    {
        $detector = app(AmbiguousIntentDetector::class);

        foreach ([
            'It might be easier to continue somewhere else',
            'We can sort it out between ourselves',
            'It would be cheaper if we skip the fees',
            'You can find me online, same name everywhere',
        ] as $body) {
            $this->assertTrue($detector->warrantsAiAnalysis($body), "Ambiguous intent should be analysed: {$body}");
        }
    }

    public function test_the_triage_reasons_are_recorded_so_the_selection_is_explainable(): void
    {
        $this->enableCommunicationModeration();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'It might be easier to continue somewhere else');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $this->assertNotNull($finding);
        $this->assertSame(['somewhere else'], $finding->detected_patterns);
    }

    public function test_an_ordinary_message_creates_no_finding_and_no_ai_run(): void
    {
        $this->enableCommunicationModeration();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'See you next Tuesday at four.');

        $this->assertNull(app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message));
        $this->assertSame(0, MessageSafetyFinding::query()->count());
        $this->assertSame(0, AiRun::query()->count());
    }

    // ── Privacy boundary ──────────────────────────────────────────────

    /** @return array<string, string> the exact variables that would be sent */
    private function promptVariables(MessageSafetyFinding $finding): array
    {
        return app(CommunicationSafetyInputResolver::class)->resolve(new AiTaskDescriptor(
            feature: AiFeature::CommunicationModeration,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'communication_risk',
            inputResolver: CommunicationSafetyInputResolver::class,
            correlationId: $finding->getKey(),
        ));
    }

    public function test_only_the_message_role_and_triage_reasons_are_sent(): void
    {
        $this->enableCommunicationModeration();

        $student = $this->namedStudent('Mira', 'Kowalski');
        $instructor = $this->namedInstructor('Priya', 'Nair');
        $conversation = $this->conversation($student, $instructor);
        $message = $this->message($conversation, $student, 'We can sort it out between ourselves');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);
        $variables = $this->promptVariables($finding);

        $this->assertSame(['sender_role', 'triage_reasons', 'message'], array_keys($variables));
        $this->assertSame('student', $variables['sender_role']);
        $this->assertStringContainsString('between ourselves', $variables['message']);
    }

    public function test_no_identity_of_either_participant_reaches_the_prompt(): void
    {
        $this->enableCommunicationModeration();

        $student = $this->namedStudent('Mira', 'Kowalski');
        $instructor = $this->namedInstructor('Priya', 'Nair');
        $conversation = $this->conversation($student, $instructor);
        $message = $this->message($conversation, $student, 'We can sort it out between ourselves');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);
        $sent = implode("\n", $this->promptVariables($finding));

        foreach (['Mira', 'Kowalski', 'Priya', 'Nair', (string) $student->email, (string) $instructor->email] as $identity) {
            $this->assertStringNotContainsString($identity, $sent);
        }

        $this->assertStringNotContainsString((string) $student->id, $sent);
        $this->assertStringNotContainsString($message->getKey(), $sent);
        $this->assertStringNotContainsString($conversation->getKey(), $sent);
    }

    /**
     * The accuracy trade this phase makes deliberately: no context, so
     * one flagged phrase can never drag a private conversation to a
     * third party.
     */
    public function test_no_other_message_from_the_conversation_is_ever_sent(): void
    {
        $this->enableCommunicationModeration();

        $student = $this->student();
        $instructor = $this->instructor();
        $conversation = $this->conversation($student, $instructor);

        $this->message($conversation, $student, 'A completely unrelated earlier message about parabolas.');
        $target = $this->message($conversation, $student, 'We can sort it out between ourselves');
        $this->message($conversation, $instructor, 'A later reply mentioning her exam results.');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($target);
        $sent = implode("\n", $this->promptVariables($finding));

        $this->assertStringNotContainsString('parabolas', $sent);
        $this->assertStringNotContainsString('exam results', $sent);
    }

    public function test_the_finding_stores_no_message_text(): void
    {
        $this->enableCommunicationModeration();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'We can sort it out between ourselves about parabolas');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $stored = json_encode($finding->fresh()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('parabolas', $stored);
    }

    public function test_the_queued_payload_carries_no_message_content(): void
    {
        $this->enableCommunicationModeration();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'We can sort it out between ourselves about parabolas');

        $finding = app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $payload = serialize(new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::CommunicationModeration,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'communication_risk',
            inputResolver: CommunicationSafetyInputResolver::class,
            correlationId: $finding->getKey(),
        )));

        $this->assertStringNotContainsString('parabolas', $payload);
        $this->assertStringNotContainsString('between ourselves', $payload);
    }

    /** No person asked for this analysis, and ai_runs should say so. */
    public function test_an_automatic_run_records_no_requesting_user(): void
    {
        $this->enableCommunicationModeration();
        $this->useFakedOpenAiCompletion($this->riskPayload());

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'We can sort it out between ourselves');

        app(MessageSafetyServiceInterface::class)->requestIntentAnalysis($message);

        $run = AiRun::query()->sole();

        $this->assertNull($run->requested_by);
        $this->assertSame(AiFeature::CommunicationModeration, $run->feature_key);
    }

    public function test_a_deterministic_finding_is_recorded_without_any_provider_call(): void
    {
        $this->enableCommunicationModeration();
        Http::fake();

        $conversation = $this->conversation($this->student(), $instructor = $this->instructor());
        $message = $this->message($conversation, $instructor, 'My email is tutor@example.com', ['email_address']);

        $finding = app(MessageSafetyServiceInterface::class)->recordDeterministicFinding($message);

        $this->assertNotNull($finding);
        $this->assertSame(MessageSafetySource::Deterministic, $finding->source_type);
        $this->assertSame(['email_address'], $finding->detected_patterns);
        $this->assertNull($finding->confidence);
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }
}
