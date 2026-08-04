<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\VerifyEmailNotice;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Services\Auth\EmailVerificationOtpService;
use App\Support\PendingEmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Email verification is one-time-code based: registration leaves the
 * account pending WITHOUT a session, the code screen is reachable only by
 * a session that registered the account or proved its password, and a
 * correct code both activates the account and signs the user in.
 */
class EmailOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): User
    {
        return User::factory()->create([
            'status' => User::STATUS_PENDING,
            'email_verified_at' => null,
        ]);
    }

    /** Issues a real challenge and returns the plain code that was mailed. */
    private function issueCode(User $user): string
    {
        Notification::fake();

        app(EmailVerificationOtpService::class)->issue($user);

        $code = null;

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return (string) $code;
    }

    public function test_a_correct_code_verifies_activates_and_signs_the_user_in(): void
    {
        $user = $this->pendingUser();
        $code = $this->issueCode($user);

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $code)
            ->call('verify')
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNull(PendingEmailVerification::userId());
    }

    public function test_a_wrong_code_neither_verifies_nor_signs_in(): void
    {
        $user = $this->pendingUser();
        $realCode = $this->issueCode($user);
        $wrongCode = str_pad((string) ((((int) $realCode) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $wrongCode)
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->pendingUser();
        $code = $this->issueCode($user);

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)->set('code', $code)->call('verify');

        $this->assertSame(1, EmailVerificationChallenge::query()->whereNotNull('consumed_at')->count());

        // A second submission of the same code finds no live challenge.
        auth()->logout();
        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $code)
            ->call('verify')
            ->assertHasErrors('code');
    }

    public function test_wrong_codes_burn_the_challenge_attempt_ceiling(): void
    {
        $user = $this->pendingUser();
        $code = $this->issueCode($user);

        PendingEmailVerification::remember($user);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(VerifyEmailNotice::class)
                ->set('code', '000000')
                ->call('verify')
                ->assertHasErrors('code');
        }

        $this->assertSame(0, (int) EmailVerificationChallenge::query()->value('attempts_remaining'));

        // Exhausted: even the real code no longer works.
        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $code)
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->pendingUser();
        $code = $this->issueCode($user);

        EmailVerificationChallenge::query()->update(['expires_at' => now()->subMinute()]);

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $code)
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_issuing_a_new_code_invalidates_the_previous_one(): void
    {
        $user = $this->pendingUser();
        $first = $this->issueCode($user);
        $this->issueCode($user);

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $first)
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_code_screen_is_not_reachable_without_a_pending_session(): void
    {
        $this->pendingUser();

        $this->get(route('auth.verification.notice'))
            ->assertRedirect(route('auth.login'));
    }
}
