<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\StudentStatus;
use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralAttributionSource;
use App\Referral\Events\ReferralAttributed;
use App\Settings\FeatureSettings;
use App\Settings\LoginSecuritySettings;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $registration = app(RegistrationSettings::class);
        $registration->self_registration_enabled = true;
        // The testing default is null — no role would be assigned and the
        // student-only attribution block would be skipped entirely.
        $registration->default_role = 'student';
        $registration->save();

        $features = app(FeatureSettings::class);
        $features->referral_enabled = true;
        $features->save();
    }

    private function referrerWithCode(): ReferralCode
    {
        $referrer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $referrer->assignRole('student');
        $referrer->profile?->update(['student_status' => StudentStatus::Active]);

        return app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($referrer);
    }

    /** @param  array<string, mixed>  $overrides */
    private function classicRegister(array $overrides = []): TestResponse
    {
        $response = $this->post(route('auth.register.store'), array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane-'.uniqid().'@gmail.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'terms' => '1',
        ], $overrides));

        // Successful registration logs the new user in; log out so the
        // next attempt is not bounced by the route's `guest` middleware.
        auth()->logout();

        return $response;
    }

    public function test_registration_without_a_code_creates_no_attribution(): void
    {
        $this->classicRegister(['email' => 'plain@gmail.com']);

        $this->assertNotNull(User::where('email', 'plain@gmail.com')->first());
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_classic_registration_with_a_valid_code_attributes_once_with_manual_source(): void
    {
        $code = $this->referrerWithCode();

        $this->classicRegister(['email' => 'referred@gmail.com', 'referral_code' => strtolower($code->code)]);

        $referred = User::where('email', 'referred@gmail.com')->firstOrFail();
        $attribution = ReferralAttribution::query()->sole();

        $this->assertSame($code->user_id, $attribution->referrer_id);
        $this->assertSame($referred->id, $attribution->referred_student_id);
        $this->assertSame($code->id, $attribution->referral_code_id);
        $this->assertSame(ReferralAttributionSource::Manual, $attribution->source);
        $this->assertNotNull($attribution->attributed_at);
    }

    public function test_livewire_ref_query_param_prefills_and_records_link_source(): void
    {
        $code = $this->referrerWithCode();

        Livewire::withQueryParams(['ref' => $code->code])
            ->test(RegisterForm::class)
            ->assertSet('referral_code', $code->code)
            ->set('first_name', 'Linked')
            ->set('email', 'linked@gmail.com')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->call('register');

        $attribution = ReferralAttribution::query()->sole();

        $this->assertSame(ReferralAttributionSource::Link, $attribution->source);
    }

    public function test_livewire_manually_typed_code_records_manual_source(): void
    {
        $code = $this->referrerWithCode();

        Livewire::test(RegisterForm::class)
            ->set('first_name', 'Manual')
            ->set('email', 'manual@gmail.com')
            ->set('password', 'StrongPass123!')
            ->set('password_confirmation', 'StrongPass123!')
            ->set('terms', true)
            ->set('referral_code', $code->code)
            ->call('register');

        $this->assertSame(ReferralAttributionSource::Manual, ReferralAttribution::query()->sole()->source);
    }

    public function test_unknown_disabled_and_malformed_codes_never_block_registration(): void
    {
        $disabled = ReferralCode::factory()->disabled()->create();

        foreach ([
            'unknown@gmail.com' => 'ZZZZ9999',
            'disabled@gmail.com' => $disabled->code,
            'malformed@gmail.com' => '!!! not-a-code !!!',
        ] as $email => $badCode) {
            $this->classicRegister(['email' => $email, 'referral_code' => $badCode]);

            $this->assertNotNull(User::where('email', $email)->first(), "Registration with code '{$badCode}' must still succeed.");
        }

        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_feature_disabled_skips_attribution_but_not_registration(): void
    {
        $code = $this->referrerWithCode();

        $features = app(FeatureSettings::class);
        $features->referral_enabled = false;
        $features->save();

        $this->classicRegister(['email' => 'featureless@gmail.com', 'referral_code' => $code->code]);

        $this->assertNotNull(User::where('email', 'featureless@gmail.com')->first());
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_instructor_owned_code_creates_no_attribution(): void
    {
        // A student's code whose owner later became instructor-only.
        $code = $this->referrerWithCode();
        $code->user->syncRoles(['instructor']);

        $this->classicRegister(['email' => 'nope@gmail.com', 'referral_code' => $code->code]);

        $this->assertNotNull(User::where('email', 'nope@gmail.com')->first());
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_suspended_or_archived_referrer_creates_no_attribution(): void
    {
        $code = $this->referrerWithCode();
        $code->user->profile?->update(['student_status' => StudentStatus::Suspended]);

        $this->classicRegister(['email' => 'suspended-ref@gmail.com', 'referral_code' => $code->code]);

        $this->assertSame(0, ReferralAttribution::query()->count());

        $code->user->profile?->update(['student_status' => StudentStatus::Archived]);

        $this->classicRegister(['email' => 'archived-ref@gmail.com', 'referral_code' => $code->code]);

        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_self_referral_is_rejected_at_service_level(): void
    {
        $code = $this->referrerWithCode();

        $attribution = app(ReferralAttributionServiceInterface::class)
            ->attributeFromRegistration($code->user, $code->code);

        $this->assertNull($attribution);
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_a_referred_student_can_never_gain_a_second_attribution(): void
    {
        $codeA = $this->referrerWithCode();
        $codeB = $this->referrerWithCode();

        $this->classicRegister(['email' => 'once@gmail.com', 'referral_code' => $codeA->code]);

        $referred = User::where('email', 'once@gmail.com')->firstOrFail();

        // A later attempt (any code, any path) is silently ignored.
        $second = app(ReferralAttributionServiceInterface::class)
            ->attributeFromRegistration($referred, $codeB->code);

        $this->assertNull($second);

        $attribution = ReferralAttribution::query()->sole();
        $this->assertSame($codeA->user_id, $attribution->referrer_id);
    }

    public function test_failed_registration_leaves_no_user_and_no_attribution(): void
    {
        $code = $this->referrerWithCode();

        $registration = app(RegistrationSettings::class);
        $registration->default_role = 'role-that-does-not-exist';
        $registration->save();

        $this->classicRegister(['email' => 'doomed@gmail.com', 'referral_code' => $code->code]);

        $this->assertNull(User::where('email', 'doomed@gmail.com')->first());
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_attributed_event_is_dispatched_with_identifiers_only(): void
    {
        Event::fake([ReferralAttributed::class]);

        $code = $this->referrerWithCode();

        $this->classicRegister(['email' => 'evented@gmail.com', 'referral_code' => $code->code]);

        $referred = User::where('email', 'evented@gmail.com')->firstOrFail();

        Event::assertDispatched(ReferralAttributed::class, function (ReferralAttributed $event) use ($code, $referred): bool {
            return $event->referrerId === $code->user_id
                && $event->referredStudentId === $referred->id
                && $event->attributionId === ReferralAttribution::query()->sole()->id;
        });
    }

    public function test_no_event_is_dispatched_for_an_invalid_code(): void
    {
        Event::fake([ReferralAttributed::class]);

        $this->classicRegister(['email' => 'no-event@gmail.com', 'referral_code' => 'ZZZZ9999']);

        Event::assertNotDispatched(ReferralAttributed::class);
    }

    public function test_instructor_registration_path_never_attributes(): void
    {
        $code = $this->referrerWithCode();

        $registration = app(RegistrationSettings::class);
        $registration->default_role = 'instructor';
        $registration->save();

        $this->classicRegister(['email' => 'new-instructor@gmail.com', 'referral_code' => $code->code]);

        $this->assertNotNull(User::where('email', 'new-instructor@gmail.com')->first());
        $this->assertSame(0, ReferralAttribution::query()->count());
    }

    public function test_classic_registration_route_is_throttled(): void
    {
        $settings = app(LoginSecuritySettings::class);
        $settings->throttling_enabled = true;
        $settings->save();

        $limited = false;

        for ($i = 0; $i < 11; $i++) {
            $response = $this->classicRegister(['email' => 'throttle-me@gmail.com']);

            if ($response->status() === 429) {
                $limited = true;

                break;
            }
        }

        $this->assertTrue($limited, 'Expected the classic POST /register route to rate-limit within 11 attempts.');
    }

    public function test_livewire_registration_remains_throttled(): void
    {
        $settings = app(LoginSecuritySettings::class);
        $settings->throttling_enabled = true;
        $settings->save();

        // The Livewire limiter counts after validation, so repeated
        // valid submissions with one email are needed — an invalid
        // default role makes every attempt fail AFTER being counted,
        // without ever consuming the email's uniqueness.
        $registration = app(RegistrationSettings::class);
        $registration->default_role = 'role-that-does-not-exist';
        $registration->save();

        $limited = false;

        for ($i = 0; $i < 11; $i++) {
            $component = Livewire::test(RegisterForm::class)
                ->set('first_name', 'Rate')
                ->set('email', 'livewire-throttle@gmail.com')
                ->set('password', 'StrongPass123!')
                ->set('password_confirmation', 'StrongPass123!')
                ->set('terms', true)
                ->call('register');

            $error = $component->errors()->first('email');

            if ($error && str_contains($error, 'Too many attempts')) {
                $limited = true;

                break;
            }
        }

        $this->assertTrue($limited, 'Expected the Livewire registration path to rate-limit within 11 attempts.');
    }
}
