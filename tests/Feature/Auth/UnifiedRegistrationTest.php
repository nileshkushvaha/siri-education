<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\Country;
use App\Models\Currency;
use App\Settings\RegistrationSettings;
use App\Support\InstructorApplicationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
