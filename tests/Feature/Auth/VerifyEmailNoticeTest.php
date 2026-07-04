<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\VerifyEmailNotice;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class VerifyEmailNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_on_the_verify_email_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('auth.verification.notice'))
            ->assertOk()
            ->assertSeeLivewire(VerifyEmailNotice::class);
    }

    public function test_resend_sends_a_new_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        Livewire::actingAs($user)
            ->test(VerifyEmailNotice::class)
            ->call('resend')
            ->assertSet('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_already_verified_user_is_redirected_instead_of_resending(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->test(VerifyEmailNotice::class)
            ->call('resend')
            ->assertRedirect(route('dashboard'));

        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    public function test_rate_limit_blocks_after_six_resends_in_a_minute(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        for ($i = 0; $i < 6; $i++) {
            Livewire::actingAs($user)->test(VerifyEmailNotice::class)->call('resend');
        }

        Livewire::actingAs($user)
            ->test(VerifyEmailNotice::class)
            ->call('resend')
            ->assertSet('status', 'throttled');
    }
}
