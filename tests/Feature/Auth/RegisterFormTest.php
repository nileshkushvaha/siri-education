<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegisterFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RegistrationService::resolveDefaultRole() throws a
        // RegistrationException if RegistrationSettings::default_role
        // is configured (here: 'student') but the role doesn't exist —
        // RefreshDatabase migrates but doesn't seed roles.
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(RegistrationSettings::class)->self_registration_enabled = true;
        app(RegistrationSettings::class)->save();
    }

    public function test_renders_on_the_register_page(): void
    {
        $this->get(route('auth.register'))
            ->assertOk()
            ->assertSeeLivewire(RegisterForm::class);
    }

    public function test_validation_blocks_incomplete_submission(): void
    {
        Livewire::test(RegisterForm::class)
            ->set('first_name', '')
            ->set('email', 'not-an-email')
            ->set('terms', false)
            ->call('register')
            ->assertHasErrors(['first_name' => 'required', 'email' => 'email', 'terms' => 'accepted']);
    }

    public function test_password_confirmation_mismatch_is_caught(): void
    {
        Livewire::test(RegisterForm::class)
            ->set('first_name', 'Jane')
            ->set('email', 'jane@gmail.com')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'Mismatch123!')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('password');
    }

    public function test_valid_submission_creates_a_user_via_registration_service(): void
    {
        Livewire::test(RegisterForm::class)
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'jane-doe@gmail.com')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->call('register');

        $this->assertDatabaseHas('users', ['email' => 'jane-doe@gmail.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@gmail.com']);

        Livewire::test(RegisterForm::class)
            ->set('first_name', 'Jane')
            ->set('email', 'taken@gmail.com')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('email');
    }
}
