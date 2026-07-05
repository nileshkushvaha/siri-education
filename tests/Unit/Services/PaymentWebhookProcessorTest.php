<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Activity;
use App\Services\Payment\PaymentWebhookProcessor;
use App\Settings\PaymentAdvancedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_webhook_is_recorded_via_the_audit_trail_not_raw_activity(): void
    {
        app(PaymentWebhookProcessor::class)->process(
            'stripe',
            ['type' => 'payment_intent.succeeded', 'id' => 'pi_123'],
            [],
        );

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'payments',
            'event' => 'webhook_received',
        ]);
    }

    public function test_webhook_activity_does_not_store_the_raw_payload(): void
    {
        app(PaymentWebhookProcessor::class)->process(
            'stripe',
            ['type' => 'payment_intent.succeeded', 'id' => 'pi_123', 'card_number' => '4242424242424242'],
            [],
        );

        $activity = Activity::where('log_name', 'payments')->firstOrFail();

        $this->assertNull($activity->properties->get('payload'));
        $this->assertNull($activity->properties->get('card_number'));
        $this->assertSame('pi_123', $activity->properties->get('reference'));
    }

    public function test_webhook_is_not_logged_when_audit_log_disabled(): void
    {
        $settings = app(PaymentAdvancedSettings::class);
        $settings->enable_audit_log = false;
        $settings->save();

        app(PaymentWebhookProcessor::class)->process('stripe', ['type' => 'ping'], []);

        $this->assertDatabaseMissing('activity_log', ['log_name' => 'payments']);
    }

    public function test_webhook_activity_actor_type_is_system(): void
    {
        app(PaymentWebhookProcessor::class)->process('stripe', ['type' => 'ping'], []);

        $activity = Activity::where('log_name', 'payments')->firstOrFail();
        $this->assertTrue($activity->isSystem());
    }
}
