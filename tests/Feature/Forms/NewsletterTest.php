<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Enums\NewsletterSubscriberStatus;
use App\Livewire\Frontend\Cms\Newsletter;
use App\Models\NewsletterSubscriber;
use App\Notifications\Newsletter\NewsletterWelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    private function baseProps(): array
    {
        return [
            'emailLabel' => 'Email address',
            'buttonText' => 'Subscribe',
        ];
    }

    public function test_validation_requires_a_valid_email(): void
    {
        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('email', 'not-an-email')
            ->call('subscribe')
            ->assertHasErrors(['email']);
    }

    public function test_honeypot_field_rejects_bots(): void
    {
        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('email', 'bot@sirieducation.com')
            ->set('website', 'http://spam.example')
            ->call('subscribe')
            ->assertHasErrors(['website']);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_successful_subscription_persists_and_sends_welcome_email(): void
    {
        Notification::fake();

        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('name', 'Sam')
            ->set('email', 'sam@sirieducation.com')
            ->call('subscribe')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $subscriber = NewsletterSubscriber::firstWhere('email', 'sam@sirieducation.com');
        $this->assertNotNull($subscriber);
        $this->assertSame(NewsletterSubscriberStatus::Subscribed, $subscriber->status);
        $this->assertNotEmpty($subscriber->unsubscribe_token);

        Notification::assertSentOnDemand(NewsletterWelcomeNotification::class);
    }

    public function test_resubscribing_with_same_email_updates_existing_row(): void
    {
        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('email', 'sam@sirieducation.com')
            ->call('subscribe');

        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('email', 'sam@sirieducation.com')
            ->call('subscribe');

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_unsubscribe_marks_subscriber_as_unsubscribed(): void
    {
        Livewire::test(Newsletter::class, $this->baseProps())
            ->set('email', 'sam@sirieducation.com')
            ->call('subscribe');

        $subscriber = NewsletterSubscriber::firstWhere('email', 'sam@sirieducation.com');

        $this->get(route('newsletter.unsubscribe', $subscriber->unsubscribe_token))
            ->assertOk()
            ->assertSee('unsubscribed');

        $this->assertSame(NewsletterSubscriberStatus::Unsubscribed, $subscriber->fresh()->status);
    }

    public function test_unsubscribe_with_invalid_token_shows_not_found_message(): void
    {
        $this->get(route('newsletter.unsubscribe', 'invalid-token'))
            ->assertOk()
            ->assertSee('invalid');
    }
}
