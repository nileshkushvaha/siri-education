<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Forms\Enums\PublicFormType;
use App\Livewire\Frontend\Forms\SupportForm;
use App\Models\PublicFormSubmission;
use App\Notifications\Forms\PublicFormSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SupportFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_the_livewire_component(): void
    {
        $this->get(route('forms.support'))
            ->assertOk()
            ->assertSeeLivewire(SupportForm::class);
    }

    public function test_validation_requires_core_fields(): void
    {
        Livewire::test(SupportForm::class)
            ->set('name', '')
            ->set('email', '')
            ->set('subject', '')
            ->set('message', '')
            ->call('submit')
            ->assertHasErrors(['name' => 'required', 'email' => 'required', 'subject' => 'required', 'message' => 'required']);
    }

    public function test_invalid_priority_is_rejected(): void
    {
        Livewire::test(SupportForm::class)
            ->set('name', 'Alex')
            ->set('email', 'alex@example.com')
            ->set('subject', 'Login issue')
            ->set('message', 'Cannot log in.')
            ->set('priority', 'urgent')
            ->call('submit')
            ->assertHasErrors(['priority']);
    }

    public function test_honeypot_field_rejects_bots(): void
    {
        Livewire::test(SupportForm::class)
            ->set('name', 'Alex')
            ->set('email', 'alex@example.com')
            ->set('subject', 'Login issue')
            ->set('message', 'Cannot log in.')
            ->set('website', 'http://spam.example')
            ->call('submit')
            ->assertHasErrors(['website']);

        $this->assertDatabaseCount('public_form_submissions', 0);
    }

    public function test_successful_submission_persists_priority_in_meta(): void
    {
        Notification::fake();

        Livewire::test(SupportForm::class)
            ->set('name', 'Alex')
            ->set('email', 'alex@example.com')
            ->set('subject', 'Login issue')
            ->set('priority', 'high')
            ->set('message', 'Cannot log in.')
            ->call('submit')
            ->assertSet('submitted', true);

        $submission = PublicFormSubmission::firstWhere('subject', 'Login issue');
        $this->assertSame(PublicFormType::Support, $submission->type);
        $this->assertSame('high', $submission->meta['priority']);

        Notification::assertSentOnDemand(PublicFormSubmissionNotification::class);
    }
}
