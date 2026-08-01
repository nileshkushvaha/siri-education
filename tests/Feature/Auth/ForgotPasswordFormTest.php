<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\ForgotPasswordForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ForgotPasswordFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_on_the_forgot_password_page(): void
    {
        $this->get(route('auth.password.request'))
            ->assertOk()
            ->assertSeeLivewire(ForgotPasswordForm::class);
    }

    public function test_validation_requires_a_valid_email(): void
    {
        Livewire::test(ForgotPasswordForm::class)
            ->set('email', 'not-an-email')
            ->call('send')
            ->assertHasErrors('email');
    }

    public function test_valid_email_transitions_to_the_sent_state_regardless_of_whether_the_account_exists(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'exists@example.com']);

        Livewire::test(ForgotPasswordForm::class)
            ->set('email', 'exists@example.com')
            ->call('send')
            ->assertSet('sent', true);

        // Enumeration protection: an unknown email reaches the same state.
        Livewire::test(ForgotPasswordForm::class)
            ->set('email', 'does-not-exist@example.com')
            ->call('send')
            ->assertSet('sent', true);
    }
}
