<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\ResetPasswordForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class ResetPasswordFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_on_the_reset_password_page(): void
    {
        $this->get(route('auth.password.reset', ['token' => 'anything']))
            ->assertOk()
            ->assertSeeLivewire(ResetPasswordForm::class);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $originalHash = $user->password;

        Livewire::test(ResetPasswordForm::class, ['token' => 'bad-token', 'email' => 'reset@example.com'])
            ->set('password', 'NewStrongPass123!')
            ->set('password_confirmation', 'NewStrongPass123!')
            ->call('resetPassword')
            ->assertHasErrors('email');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_valid_token_resets_password_and_auto_logs_in(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $token = Password::createToken($user);

        Livewire::test(ResetPasswordForm::class, ['token' => $token, 'email' => 'reset@example.com'])
            ->set('password', 'NewStrongPass123!')
            ->set('password_confirmation', 'NewStrongPass123!')
            ->call('resetPassword');

        $this->assertTrue(Hash::check('NewStrongPass123!', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_confirmation_mismatch_is_caught_before_touching_the_token(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        Livewire::test(ResetPasswordForm::class, ['token' => $token, 'email' => 'reset@example.com'])
            ->set('password', 'NewStrongPass123!')
            ->set('password_confirmation', 'Mismatch123!')
            ->call('resetPassword')
            ->assertHasErrors('password');

        $this->assertGuest();
    }
}
