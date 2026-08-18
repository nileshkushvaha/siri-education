<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety;

use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Models\AiRun;
use App\Models\Message;
use App\Models\MessageSafetyFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Messaging\Safety\Concerns\BuildsMessageSafetyFixtures;
use Tests\TestCase;

/**
 * The pre-send warning: user education, never moderation.
 *
 * The properties that make it acceptable are that it blocks nothing,
 * records nothing, and calls no provider — so these are what the tests
 * assert, not just that the banner appears.
 */
class MessageSafetyWarningTest extends TestCase
{
    use BuildsMessageSafetyFixtures, CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    public function test_the_warning_is_produced_by_deterministic_rules_alone(): void
    {
        Http::fake();

        $warning = app(MessageSafetyServiceInterface::class)->warningFor('My email is tutor@example.com');

        $this->assertFalse($warning->isEmpty());
        $this->assertSame('an email address', $warning->summary());
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_an_ordinary_message_produces_no_warning(): void
    {
        $warning = app(MessageSafetyServiceInterface::class)->warningFor('See you on Tuesday at four.');

        $this->assertTrue($warning->isEmpty());
    }

    public function test_the_warning_names_every_detected_kind_in_plain_language(): void
    {
        $warning = app(MessageSafetyServiceInterface::class)->warningFor('Email me at a@b.com or WhatsApp 07700 900123');

        $summary = $warning->summary();

        $this->assertStringContainsString('an email address', $summary);
        $this->assertStringContainsString('a phone number', $summary);
        $this->assertStringContainsString('another app or payment service', $summary);
    }

    /** Producing a warning is not an event in a user's history. */
    public function test_producing_a_warning_records_nothing(): void
    {
        app(MessageSafetyServiceInterface::class)->warningFor('My email is tutor@example.com');

        $this->assertSame(0, MessageSafetyFinding::query()->count());
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'message_safety']);
    }

    // ── Through the real send flow ────────────────────────────────────

    public function test_a_first_submission_with_contact_details_is_held_and_explained(): void
    {
        $student = $this->student();
        $conversation = $this->eligibleConversation($student, $this->instructor());

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), ['body' => 'My email is tutor@example.com'])
            ->assertSessionHas('safety_warning');

        // Held, not blocked — and nothing was delivered yet.
        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_user_can_always_send_anyway(): void
    {
        $student = $this->student();
        $conversation = $this->eligibleConversation($student, $this->instructor());

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), [
                'body' => 'My email is tutor@example.com',
                'acknowledged_safety_warning' => '1',
            ])
            ->assertSessionHas('success');

        $message = Message::query()->sole();

        // Delivered verbatim: the platform never alters message text.
        $this->assertSame('My email is tutor@example.com', $message->body);
        $this->assertTrue($message->flagged_leakage);
    }

    public function test_an_ordinary_message_sends_on_the_first_attempt(): void
    {
        $student = $this->student();
        $conversation = $this->eligibleConversation($student, $this->instructor());

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), ['body' => 'See you Tuesday at four.'])
            ->assertSessionHas('success');

        $this->assertSame(1, Message::query()->count());
    }

    public function test_the_warning_path_never_calls_a_provider(): void
    {
        $this->enableCommunicationModeration();
        Http::fake();

        $student = $this->student();
        $conversation = $this->eligibleConversation($student, $this->instructor());

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), ['body' => 'My email is tutor@example.com']);

        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }
}
