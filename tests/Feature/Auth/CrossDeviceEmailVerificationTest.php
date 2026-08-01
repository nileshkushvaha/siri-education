<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Auth\EmailVerificationOutcome;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\Auth\AccountEmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cross-device email verification.
 *
 * The defect: the verification route lived behind `auth` and used
 * `EmailVerificationRequest`, which authorises against the authenticated
 * user. Opening the link on a second device redirected to a login that
 * could never succeed, because registration creates the account
 * `pending_verification` and `LoginService` rejects any non-active
 * account. The user was permanently stuck.
 */
class CrossDeviceEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Cross-device verification ────────────────────────────────────

    public function test_a_guest_with_no_session_can_verify_on_another_device(): void
    {
        $user = $this->pendingStudent();

        $response = $this->get($this->verificationUrl($user));

        $response->assertOk();
        // Crucially: not a redirect to login.
        $response->assertDontSee('Sign in to your account');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
    }

    public function test_the_guest_is_not_silently_authenticated(): void
    {
        $user = $this->pendingStudent();

        $this->get($this->verificationUrl($user))->assertOk();

        // Controlling a mailbox is not proof of the password, and a
        // forwarded link must not hand a session to whoever opens it.
        $this->assertGuest();
    }

    public function test_the_success_page_offers_a_login_action(): void
    {
        $user = $this->pendingStudent();

        $this->get($this->verificationUrl($user))
            ->assertOk()
            ->assertSee('Continue to login')
            ->assertSee(route('auth.login'), escape: false);
    }

    public function test_the_student_lifecycle_is_activated(): void
    {
        $user = $this->pendingStudent();

        $this->get($this->verificationUrl($user))->assertOk();

        $this->assertSame(StudentStatus::Active, $user->profile->fresh()->student_status);
    }

    public function test_login_succeeds_after_cross_device_verification(): void
    {
        $user = $this->pendingStudent();

        $this->get($this->verificationUrl($user))->assertOk();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $this->assertAuthenticatedAs($user->fresh());
    }

    // ── Signature and hash security ──────────────────────────────────

    public function test_an_unsigned_link_is_rejected(): void
    {
        $user = $this->pendingStudent();

        $this->get(route('auth.verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $user = $this->pendingStudent();

        $this->get($this->verificationUrl($user).'tampered')->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        Carbon::setTestNow(now()->addDays(2));

        try {
            $this->get($url)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_modified_user_id_is_rejected(): void
    {
        $user = $this->pendingStudent();
        $other = $this->pendingStudent();

        // Swapping the id invalidates the signature computed over the path.
        $url = str_replace(
            '/verify-email/'.$user->getKey().'/',
            '/verify-email/'.$other->getKey().'/',
            $this->verificationUrl($user),
        );

        $this->get($url)->assertForbidden();

        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_a_modified_hash_is_rejected(): void
    {
        $user = $this->pendingStudent();

        $url = str_replace(
            sha1($user->getEmailForVerification()),
            sha1('someone-else@example.test'),
            $this->verificationUrl($user),
        );

        $this->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unknown_user_id_is_rejected_without_confirming_existence(): void
    {
        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        $user->forceDelete();

        // Same 403 an invalid signature produces — no enumeration signal.
        $this->get($url)->assertForbidden();
    }

    public function test_a_link_issued_before_an_email_change_cannot_verify_the_new_address(): void
    {
        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        $user->forceFill(['email' => 'changed-'.$user->email])->saveQuietly();

        // The hash is compared against the CURRENT address.
        $this->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── Idempotency ──────────────────────────────────────────────────

    public function test_following_the_link_twice_is_safe(): void
    {
        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_the_verified_event_fires_exactly_once(): void
    {
        Event::fake([Verified::class]);

        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_the_student_lifecycle_transition_is_recorded_once(): void
    {
        $user = $this->pendingStudent();
        $url = $this->verificationUrl($user);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $this->assertSame(1, $this->lifecycleActivationCount($user));
    }

    public function test_a_second_call_to_the_service_is_idempotent(): void
    {
        $user = $this->pendingStudent();
        $service = app(AccountEmailVerificationService::class);

        $this->assertSame(EmailVerificationOutcome::VerifiedAndActivated, $service->verifyAndActivate($user));
        $this->assertSame(EmailVerificationOutcome::AlreadyVerified, $service->verifyAndActivate($user->fresh()));
        $this->assertSame(1, $this->lifecycleActivationCount($user));
    }

    // ── Account-status protection ────────────────────────────────────

    public function test_a_suspended_account_is_not_reactivated(): void
    {
        $user = $this->pendingStudent();
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->saveQuietly();

        $this->get($this->verificationUrl($user))->assertOk();

        $user->refresh();
        $this->assertSame(User::STATUS_SUSPENDED, $user->status);
        // The mailbox was still proven, so the email is verified.
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_blocked_account_is_not_reactivated(): void
    {
        $user = $this->pendingStudent();
        $user->forceFill(['status' => User::STATUS_BLOCKED])->saveQuietly();

        $this->get($this->verificationUrl($user))->assertOk();

        $this->assertSame(User::STATUS_BLOCKED, $user->fresh()->status);
    }

    public function test_an_approval_pending_account_is_not_activated_by_verification(): void
    {
        // STATUS_INACTIVE is what `require_admin_approval` registration
        // creates, and also what an administrator sets to disable an
        // account. Verification must never resolve that ambiguity by
        // activating.
        $user = $this->pendingStudent();
        $user->forceFill(['status' => User::STATUS_INACTIVE])->saveQuietly();

        $this->get($this->verificationUrl($user))->assertOk();

        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);
    }

    public function test_a_restricted_account_is_not_told_it_is_active(): void
    {
        $user = $this->pendingStudent();
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->saveQuietly();

        $response = $this->get($this->verificationUrl($user))->assertOk();

        $response->assertDontSee('your account is active');
        $response->assertSee('cannot be accessed at the moment');
        // Never leak the internal status name.
        $response->assertDontSee(User::STATUS_SUSPENDED);
    }

    public function test_a_restricted_account_gets_no_student_lifecycle_transition(): void
    {
        $user = $this->pendingStudent();
        $user->forceFill(['status' => User::STATUS_BLOCKED])->saveQuietly();

        $this->get($this->verificationUrl($user))->assertOk();

        $this->assertSame(StudentStatus::Registered, $user->profile->fresh()->student_status);
        $this->assertSame(0, $this->lifecycleActivationCount($user));
    }

    // ── Session behaviour ────────────────────────────────────────────

    public function test_a_browser_signed_in_as_a_different_user_is_not_replaced(): void
    {
        $user = $this->pendingStudent();
        $other = $this->activeStudent();

        $this->actingAs($other);

        $this->get($this->verificationUrl($user))->assertOk();

        // The other session is neither replaced nor logged out.
        $this->assertAuthenticatedAs($other);
        // ...and the link still did its job.
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_same_authenticated_user_is_redirected_onward(): void
    {
        $user = $this->pendingStudent();

        $this->actingAs($user);

        $this->get($this->verificationUrl($user))->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    // ── User types ───────────────────────────────────────────────────

    public function test_a_non_student_receives_no_student_lifecycle_transition(): void
    {
        $instructor = $this->pendingUser('instructor');

        $this->get($this->verificationUrl($instructor))->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $instructor->fresh()->status);
        $this->assertSame(0, $this->lifecycleActivationCount($instructor));
    }

    public function test_an_instructor_applicant_verifies_without_the_original_session(): void
    {
        $instructor = $this->pendingUser('instructor');

        // No session at all — the instructor intent lives only in the
        // registering browser's session and must not be required.
        $this->get($this->verificationUrl($instructor))->assertOk();

        $this->assertGuest();
        $this->assertSame(User::STATUS_ACTIVE, $instructor->fresh()->status);
    }

    // ── Resend recovery ──────────────────────────────────────────────

    public function test_a_resent_link_works_without_authentication(): void
    {
        $user = $this->pendingStudent();

        $this->post(route('auth.verification.resend.guest'), ['email' => $user->email])
            ->assertRedirect();

        // The resent notification uses the same named route this test
        // builds, so exercising that route proves the destination.
        $this->get($this->verificationUrl($user))->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_guest_resend_does_not_reveal_whether_an_email_exists(): void
    {
        $known = $this->pendingStudent();

        $forKnown = $this->post(route('auth.verification.resend.guest'), ['email' => $known->email]);
        $forUnknown = $this->post(route('auth.verification.resend.guest'), ['email' => 'nobody@example.test']);

        $this->assertSame($forKnown->getStatusCode(), $forUnknown->getStatusCode());
        $this->assertSame(
            session()->get('success'),
            'Verification email sent! Please check your inbox.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'auth.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    private function lifecycleActivationCount(User $user): int
    {
        return Activity::query()
            ->where('log_name', 'student')
            ->where('event', 'student_status_changed')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->get()
            ->filter(fn ($activity): bool => ($activity->properties['transition_source'] ?? null) === 'email_verification')
            ->count();
    }

    private function pendingStudent(): User
    {
        return $this->pendingUser('student');
    }

    private function pendingUser(string $roleName): User
    {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        $user = User::factory()->create([
            'status' => User::STATUS_PENDING,
            'email_verified_at' => null,
            'password' => bcrypt('Password123!'),
        ]);
        $user->assignRole($roleName);

        if ($roleName === 'student') {
            $user->profile?->update(['student_status' => StudentStatus::Registered]);
        }

        return $user;
    }

    private function activeStudent(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
        ]);
        $user->assignRole('student');

        return $user;
    }
}
