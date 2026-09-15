<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegisterFormTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

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

        $currency = Currency::factory()->create(['code' => 'INR', 'status' => 'active']);
        $this->country = Country::factory()->create([
            'name' => 'United States',
            'iso2' => 'US',
            'phone_code' => '+1',
            'default_currency_id' => $currency->id,
            'default_timezone' => 'Asia/Kolkata',
        ]);
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

    public function test_account_type_defaults_to_learn_and_to_teach_when_the_instructor_intent_is_pending(): void
    {
        Livewire::test(RegisterForm::class)->assertSet('account_type', 'student');

        $this->get(route('auth.register', ['intent' => 'instructor']))->assertOk();

        Livewire::test(RegisterForm::class)
            ->assertSet('account_type', 'instructor')
            ->assertSee('I want to')
            ->assertSee('Teach');
    }

    public function test_referral_code_is_hidden_and_discarded_when_registering_to_teach(): void
    {
        Livewire::test(RegisterForm::class)
            ->assertSee('Referral code (optional)')
            ->set('referral_code', 'ABCD2345')
            ->set('account_type', 'instructor')
            ->assertDontSee('Referral code (optional)')
            ->assertSet('referral_code', '')
            ->set('account_type', 'student')
            ->assertSee('Referral code (optional)');
    }

    public function test_an_unknown_account_type_is_rejected(): void
    {
        Livewire::test(RegisterForm::class)
            ->set('account_type', 'manager')
            ->call('register')
            ->assertHasErrors(['account_type' => 'in']);
    }

    public function test_choosing_to_teach_registers_an_instructor_only_account_and_sends_them_to_verify(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        app(RegistrationSettings::class)->default_role = 'student';
        app(RegistrationSettings::class)->save();

        $component = Livewire::test(RegisterForm::class);
        [$left, $right] = array_map('intval', explode(' + ', $component->get('captchaQuestion')));

        $component
            ->set('account_type', 'instructor')
            ->set('first_name', 'Tara')
            ->set('email', 'tara-teaches@gmail.com')
            ->set('country_id', $this->country->id)
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->set('captcha_answer', (string) ($left + $right))
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('auth.verification.notice'));

        $user = User::where('email', 'tara-teaches@gmail.com')->firstOrFail();

        $this->assertTrue($user->hasRole('instructor'));
        $this->assertFalse($user->hasRole('student'));
        $this->assertNull($user->profile->student_status);
        $this->assertSame('draft', $user->profile->instructor_status?->value);
    }

    public function test_valid_submission_creates_a_user_via_registration_service(): void
    {
        app(RegistrationSettings::class)->default_role = 'student';
        app(RegistrationSettings::class)->save();

        $component = Livewire::test(RegisterForm::class);
        [$left, $right] = array_map('intval', explode(' + ', $component->get('captchaQuestion')));

        $component
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'jane-doe@gmail.com')
            ->set('country_id', $this->country->id)
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->set('captcha_answer', (string) ($left + $right))
            ->call('register');

        $this->assertDatabaseHas('users', ['email' => 'jane-doe@gmail.com']);

        $user = User::where('email', 'jane-doe@gmail.com')->firstOrFail();

        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_accepted_at);
        $this->assertSame($user->terms_accepted_at->toDateTimeString(), $user->privacy_accepted_at->toDateTimeString());
        $this->assertSame($this->country->id, $user->profile->country_id);
        $this->assertSame('Asia/Kolkata', $user->profile->timezone);
        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('instructor'));
        $this->assertSame('registered', $user->profile->student_status?->value);
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
