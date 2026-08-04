<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\LoginForm;
use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Settings\LoginSecuritySettings;
use App\Support\PendingEmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_on_the_login_page(): void
    {
        $this->get(route('auth.login'))
            ->assertOk()
            ->assertSeeLivewire(LoginForm::class);
    }

    public function test_validation_blocks_empty_submission(): void
    {
        Livewire::test(LoginForm::class)
            ->set('email', '')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['email' => 'required', 'password' => 'required']);
    }

    public function test_wrong_password_surfaces_a_field_error_without_authenticating(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('CorrectPass123!'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', 'login@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_correct_credentials_with_remember_me_authenticate_and_persist_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('CorrectPass123!'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', 'login@example.com')
            ->set('password', 'CorrectPass123!')
            ->set('remember', true)
            ->call('login');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    public function test_unverified_email_is_sent_a_code_and_redirected_to_the_code_screen(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'unverified@example.com',
            'password' => Hash::make('CorrectPass123!'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => null,
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', 'unverified@example.com')
            ->set('password', 'CorrectPass123!')
            ->call('login')
            ->assertRedirect(route('auth.verification.notice'));

        $this->assertGuest();

        // The correct password is what allows this session to finish
        // verification — and to be signed in once the code checks out.
        $this->assertSame($user->id, PendingEmailVerification::userId());

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
    }

    public function test_named_login_rate_limiter_engages_after_repeated_failures(): void
    {
        $settings = app(LoginSecuritySettings::class);
        $settings->throttling_enabled = true;
        $settings->save();

        $limited = false;

        for ($i = 0; $i < 11; $i++) {
            $component = Livewire::test(LoginForm::class)
                ->set('email', 'rate-limit-target@example.com')
                ->set('password', 'wrong')
                ->call('login');

            $error = $component->errors()->first('email');

            if ($error && str_contains($error, 'Too many attempts')) {
                $limited = true;

                break;
            }
        }

        $this->assertTrue($limited, 'Expected the named "login" rate limiter to engage within 11 attempts.');
    }

    public function test_admin_portal_roles_are_redirected_to_the_admin_login_instead_of_authenticating_here(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $admin = User::factory()->create([
            'email' => 'admin-login@example.com',
            'password' => Hash::make('CorrectPass123!'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('super_admin');

        Livewire::test(LoginForm::class)
            ->set('email', 'admin-login@example.com')
            ->set('password', 'CorrectPass123!')
            ->call('login')
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }
}
