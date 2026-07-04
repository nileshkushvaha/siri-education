<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use App\Forms\Enums\PublicFormType;
use App\Livewire\Frontend\Forms\CallbackForm;
use App\Models\PublicFormSubmission;
use App\Notifications\Forms\PublicFormSubmissionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CallbackFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_the_livewire_component(): void
    {
        $this->get(route('forms.callback'))
            ->assertOk()
            ->assertSeeLivewire(CallbackForm::class);
    }

    public function test_validation_requires_name_and_phone(): void
    {
        Livewire::test(CallbackForm::class)
            ->set('name', '')
            ->set('phone', '')
            ->call('submit')
            ->assertHasErrors(['name' => 'required', 'phone' => 'required']);
    }

    public function test_honeypot_field_rejects_bots(): void
    {
        Livewire::test(CallbackForm::class)
            ->set('name', 'Jane Doe')
            ->set('phone', '555-0100')
            ->set('website', 'http://spam.example')
            ->call('submit')
            ->assertHasErrors(['website']);

        $this->assertDatabaseCount('public_form_submissions', 0);
    }

    public function test_successful_submission_persists_and_notifies(): void
    {
        Notification::fake();

        Livewire::test(CallbackForm::class)
            ->set('name', 'Jane Doe')
            ->set('phone', '555-0100')
            ->set('preferredTime', 'Weekday mornings')
            ->set('message', 'Question about pricing.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('public_form_submissions', [
            'type' => PublicFormType::Callback->value,
            'name' => 'Jane Doe',
            'phone' => '555-0100',
        ]);

        $submission = PublicFormSubmission::firstWhere('phone', '555-0100');
        $this->assertSame('Weekday mornings', $submission->meta['preferred_time']);

        Notification::assertSentOnDemand(PublicFormSubmissionNotification::class);
    }

    public function test_form_resets_after_successful_submission(): void
    {
        Livewire::test(CallbackForm::class)
            ->set('name', 'Jane Doe')
            ->set('phone', '555-0100')
            ->call('submit')
            ->assertSet('name', '')
            ->assertSet('phone', '');
    }

    public function test_rate_limit_blocks_after_repeated_submissions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(CallbackForm::class)
                ->set('name', 'Jane Doe '.$i)
                ->set('phone', '555-010'.$i)
                ->call('submit')
                ->assertHasNoErrors();
        }

        Livewire::test(CallbackForm::class)
            ->set('name', 'Jane Doe Six')
            ->set('phone', '555-0106')
            ->call('submit')
            ->assertHasErrors(['name']);
    }
}
