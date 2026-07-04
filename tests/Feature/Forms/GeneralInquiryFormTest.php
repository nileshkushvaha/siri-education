<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Forms\Enums\PublicFormType;
use App\Livewire\Frontend\Forms\GeneralInquiryForm;
use App\Notifications\Forms\PublicFormSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class GeneralInquiryFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_the_livewire_component(): void
    {
        $this->get(route('forms.inquiry'))
            ->assertOk()
            ->assertSeeLivewire(GeneralInquiryForm::class);
    }

    public function test_validation_requires_core_fields(): void
    {
        Livewire::test(GeneralInquiryForm::class)
            ->set('name', '')
            ->set('email', '')
            ->set('subject', '')
            ->set('message', '')
            ->call('submit')
            ->assertHasErrors(['name' => 'required', 'email' => 'required', 'subject' => 'required', 'message' => 'required']);
    }

    public function test_honeypot_field_rejects_bots(): void
    {
        Livewire::test(GeneralInquiryForm::class)
            ->set('name', 'Jamie')
            ->set('email', 'jamie@example.com')
            ->set('subject', 'Partnership')
            ->set('message', 'Interested in partnering.')
            ->set('website', 'http://spam.example')
            ->call('submit')
            ->assertHasErrors(['website']);

        $this->assertDatabaseCount('public_form_submissions', 0);
    }

    public function test_successful_submission_persists_and_notifies(): void
    {
        Notification::fake();

        Livewire::test(GeneralInquiryForm::class)
            ->set('name', 'Jamie')
            ->set('email', 'jamie@example.com')
            ->set('subject', 'Partnership')
            ->set('message', 'Interested in partnering.')
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('public_form_submissions', [
            'type' => PublicFormType::GeneralInquiry->value,
            'subject' => 'Partnership',
        ]);

        Notification::assertSentOnDemand(PublicFormSubmissionNotification::class);
    }
}
