<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Settings\RegistrationSettings;
use App\Support\InstructorApplicationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The registration route is role-neutral (/register
 * canonical) rather than student-only (/student-registration). Covers
 * the route flip, the backward-compatible redirect (including query
 * string preservation for the instructor-intent flow), and that
 * registration logic/validation/wording were not otherwise disturbed.
 */
final class UnifiedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $settings = app(RegistrationSettings::class);
        $settings->self_registration_enabled = true;
        $settings->require_admin_approval = false;
        $settings->auto_verify_email = false;
        $settings->send_welcome_email = true;
        $settings->default_role = null;
        $settings->save();

        $currency = Currency::factory()->create(['code' => 'INR', 'status' => 'active']);
        $this->country = Country::factory()->create([
            'name' => 'United States',
            'iso2' => 'US',
            'phone_code' => '+1',
            'default_currency_id' => $currency->id,
            'default_timezone' => 'Asia/Kolkata',
        ]);
    }

    public function test_register_route_loads_the_registration_form(): void
    {
        $this->get(route('auth.register'))
            ->assertOk()
            ->assertSeeLivewire(RegisterForm::class);
    }

    public function test_register_url_is_the_canonical_path(): void
    {
        $this->assertSame('/register', parse_url(route('auth.register'), PHP_URL_PATH));
    }

    public function test_old_student_registration_url_redirects_to_register(): void
    {
        $this->get('/student-registration')
            ->assertRedirect(route('auth.register'))
            ->assertStatus(301);
    }

    public function test_old_url_redirect_preserves_the_instructor_intent_query_string(): void
    {
        $this->get('/student-registration?intent=instructor')
            ->assertRedirect(route('auth.register', ['intent' => 'instructor']));
    }

    public function test_instructor_intent_is_still_captured_on_the_canonical_route(): void
    {
        $this->get(route('auth.register', ['intent' => 'instructor']))->assertOk();

        $this->assertTrue(InstructorApplicationIntent::pending());
    }

    public function test_become_instructor_cta_still_links_to_the_canonical_register_route(): void
    {
        $response = $this->get(route('instructor.apply'))->assertOk();

        $response->assertSee(route('auth.register', ['intent' => 'instructor']), false);
    }

    public function test_registration_form_still_works_end_to_end_on_the_new_route(): void
    {
        session()->put('registration.captcha', '7');

        $response = $this->post(route('auth.register.store'), [
            'account_type' => 'student',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.unified.registration.test@gmail.com',
            'country_id' => $this->country->id,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => '1',
            'captcha_answer' => '7',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'ada.unified.registration.test@gmail.com']);
    }

    /** @return array<string, mixed> */
    private function payload(string $accountType, string $email): array
    {
        session()->put('registration.captcha', '7');

        return [
            'account_type' => $accountType,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $email,
            'country_id' => $this->country->id,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => '1',
            'captcha_answer' => '7',
            'referral_code' => 'ABCD2345',
        ];
    }

    public function test_account_type_is_required(): void
    {
        $payload = $this->payload('student', 'ada.no-type@gmail.com');
        unset($payload['account_type']);

        $this->from(route('auth.register'))
            ->post(route('auth.register.store'), $payload)
            ->assertRedirect(route('auth.register'))
            ->assertSessionHasErrors('account_type');

        $this->post(route('auth.register.store'), ['account_type' => 'manager'] + $payload)
            ->assertSessionHasErrors('account_type');

        $this->assertDatabaseMissing('users', ['email' => 'ada.no-type@gmail.com']);
    }

    public function test_choosing_to_teach_registers_an_instructor_only_account(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        app(RegistrationSettings::class)->default_role = 'student';
        app(RegistrationSettings::class)->save();

        $this->post(route('auth.register.store'), $this->payload('instructor', 'ada.teaches@gmail.com'))
            ->assertRedirect(route('auth.verification.notice'));

        $user = User::query()->where('email', 'ada.teaches@gmail.com')->firstOrFail();

        $this->assertTrue($user->hasRole('instructor'));
        $this->assertFalse($user->hasRole('student'));
        $this->assertNull($user->profile->student_status);
        $this->assertSame(InstructorStatus::Draft, $user->profile->instructor_status);
        $this->assertNotNull($user->profile->instructor_application_started_at);
        $this->assertTrue(InstructorApplicationIntent::pending(), 'the wizard is the next screen after verification');
        $this->assertDatabaseHas('activity_log', ['event' => 'application_started', 'subject_id' => $user->id]);
        $this->assertDatabaseMissing('referral_attributions', ['referred_student_id' => $user->id]);
    }

    public function test_choosing_to_learn_registers_a_student_exactly_as_before(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        app(RegistrationSettings::class)->default_role = 'student';
        app(RegistrationSettings::class)->save();

        // Even arriving through the instructor link, the submitted choice wins.
        $this->get(route('auth.register', ['intent' => 'instructor']))->assertOk();

        $this->post(route('auth.register.store'), $this->payload('student', 'ada.learns@gmail.com'))
            ->assertRedirect(route('auth.verification.notice'));

        $user = User::query()->where('email', 'ada.learns@gmail.com')->firstOrFail();

        $this->assertTrue($user->hasRole('student'));
        $this->assertFalse($user->hasRole('instructor'));
        $this->assertSame(StudentStatus::Registered, $user->profile->student_status);
        $this->assertNull($user->profile->instructor_status);
        $this->assertFalse(InstructorApplicationIntent::pending());
    }

    public function test_registration_page_no_longer_uses_student_only_wording(): void
    {
        $response = $this->get(route('auth.register'))->assertOk();

        $response->assertDontSee('Student registration');
        $response->assertDontSee('Free student account');
        $response->assertDontSee('Your student account');
        $response->assertDontSee('Create student account');
        $response->assertSee('Create your account');
    }

    public function test_registration_page_title_and_meta_description_are_role_neutral(): void
    {
        $response = $this->get(route('auth.register'))->assertOk();

        $response->assertSee('Create Your Account', false);
        $response->assertDontSee('Create Student Account', false);
    }

    public function test_login_page_cta_no_longer_says_student_account(): void
    {
        $response = $this->get(route('auth.login'))->assertOk();

        $response->assertDontSee('Create a free student account');
    }
}
