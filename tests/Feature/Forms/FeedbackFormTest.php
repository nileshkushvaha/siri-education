<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Forms\Enums\PublicFormType;
use App\Livewire\Frontend\Forms\FeedbackForm;
use App\Models\PublicFormSubmission;
use App\Notifications\Forms\PublicFormSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FeedbackFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_the_livewire_component(): void
    {
        $this->get(route('forms.feedback'))
            ->assertOk()
            ->assertSeeLivewire(FeedbackForm::class);
    }

    public function test_validation_requires_message(): void
    {
        Livewire::test(FeedbackForm::class)
            ->set('message', '')
            ->call('submit')
            ->assertHasErrors(['message' => 'required']);
    }

    public function test_name_and_email_are_optional(): void
    {
        Notification::fake();

        Livewire::test(FeedbackForm::class)
            ->set('message', 'Great platform overall.')
            ->set('rating', 4)
            ->call('submit')
            ->assertHasNoErrors();

        $submission = PublicFormSubmission::firstWhere('message', 'Great platform overall.');
        $this->assertSame('Anonymous', $submission->name);
        $this->assertNull($submission->email);
        $this->assertSame(4, $submission->meta['rating']);
    }

    public function test_honeypot_field_rejects_bots(): void
    {
        Livewire::test(FeedbackForm::class)
            ->set('message', 'Spam message')
            ->set('website', 'http://spam.example')
            ->call('submit')
            ->assertHasErrors(['website']);

        $this->assertDatabaseCount('public_form_submissions', 0);
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        Livewire::test(FeedbackForm::class)
            ->set('message', 'Test')
            ->set('rating', 6)
            ->call('submit')
            ->assertHasErrors(['rating']);
    }

    public function test_successful_submission_notifies_recipient(): void
    {
        Notification::fake();

        Livewire::test(FeedbackForm::class)
            ->set('name', 'Sam')
            ->set('email', 'sam@example.com')
            ->set('rating', 5)
            ->set('message', 'Loved it.')
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('public_form_submissions', [
            'type' => PublicFormType::Feedback->value,
            'name' => 'Sam',
        ]);

        Notification::assertSentOnDemand(PublicFormSubmissionNotification::class);
    }
}
