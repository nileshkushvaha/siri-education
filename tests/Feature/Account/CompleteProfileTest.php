<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Booking\DTOs\WizardBookingData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\WizardBookingService;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\Student\StudentProfileCompletenessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompleteProfileTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        foreach (['student', 'instructor'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $currency = Currency::factory()->create(['code' => 'USD', 'status' => 'active']);
        $this->country = Country::factory()->create([
            'name' => 'United States', 'iso2' => 'US', 'phone_code' => '+1',
            'default_currency_id' => $currency->id, 'default_timezone' => 'America/New_York',
        ]);
    }

    public function test_form_registered_student_is_complete_and_never_redirected(): void
    {
        $student = $this->student();
        $student->profile->update(['country_id' => $this->country->id, 'phone_e164' => '+12025550123', 'phone' => '+12025550123']);
        $student->forceFill(['terms_accepted_at' => now(), 'privacy_accepted_at' => now()])->save();

        $this->assertTrue(app(StudentProfileCompletenessService::class)->isComplete($student->fresh()));

        $this->actingAs($student)->get(route('booking.create'))->assertOk();
        $this->actingAs($student)->get(route('account.complete-profile'))->assertRedirect();
    }

    public function test_missing_reports_each_gap(): void
    {
        $student = $this->student(['first_name' => '', 'name' => '']);

        $this->assertSame(
            ['name', 'country', 'phone', 'terms'],
            app(StudentProfileCompletenessService::class)->missing($student->fresh()),
        );
    }

    public function test_instructors_are_never_gated(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'password_changed_at' => now()]);
        $instructor->assignRole('instructor');

        $this->assertTrue(app(StudentProfileCompletenessService::class)->isComplete($instructor));
        $this->assertSame([], app(StudentProfileCompletenessService::class)->missing($instructor));
    }

    public function test_incomplete_student_is_redirected_from_booking_with_intended_url_kept(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->get(route('booking.create'))
            ->assertRedirect(route('account.complete-profile'))
            ->assertSessionHas('info');

        $this->assertSame(url(route('booking.create')), session('url.intended'));
    }

    public function test_wizard_service_refuses_to_book_for_an_incomplete_student(): void
    {
        $student = $this->student();
        $this->actingAs($student);

        $data = new WizardBookingData(
            typeKey: 'free_demo', subject: 'Maths', grade: 5,
            startsAt: CarbonImmutable::now()->addDay(), timezone: 'UTC',
        );

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/complete your profile/i');

        app(WizardBookingService::class)->book($data);
    }

    public function test_store_completes_the_profile_and_seeds_timezone_from_country(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('account.complete-profile.store'), [
            'first_name' => 'Sam', 'last_name' => 'Lee',
            'country_id' => $this->country->id,
            'phone_country_iso2' => 'US', 'phone' => '(202) 555-0123',
            'terms' => '1',
        ])->assertRedirect(route('booking.create'));

        $student->refresh();
        $this->assertSame('Sam', $student->first_name);
        $this->assertSame($this->country->id, $student->profile->country_id);
        $this->assertSame('+12025550123', $student->profile->phone_e164);
        $this->assertSame('America/New_York', $student->profile->timezone);
        $this->assertNotNull($student->terms_accepted_at);
        $this->assertTrue(app(StudentProfileCompletenessService::class)->isComplete($student));
    }

    public function test_store_never_overwrites_an_existing_timezone(): void
    {
        $student = $this->student();
        $student->profile->update(['timezone' => 'Europe/London']);

        $this->actingAs($student)->post(route('account.complete-profile.store'), [
            'first_name' => 'Sam', 'country_id' => $this->country->id,
            'phone_country_iso2' => 'US', 'phone' => '2025550123', 'terms' => '1',
        ]);

        $this->assertSame('Europe/London', $student->fresh()->profile->timezone);
    }

    /** @param array<string, mixed> $attributes */
    private function student(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'first_name' => 'Form',
            'last_name' => 'Student',
            'password' => Hash::make('Passw0rd!Passw0rd!'),
            'password_changed_at' => now(),
            'must_change_password' => false,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'terms_accepted_at' => null,
            'privacy_accepted_at' => null,
        ], $attributes));
        $user->assignRole('student');

        return $user;
    }
}
