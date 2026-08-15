<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TZ-1 (TZ-AUD-013): a client cannot redefine which timezone an
 * authenticated student's wall-clock booking input is interpreted in.
 *
 * This matters because both the Livewire wizard and the JSON API accept
 * a NAIVE `starts_at` — "2026-08-17 09:00" with no offset. The timezone
 * used to read that string decides the actual UTC instant persisted, so
 * whoever controls the timezone controls when the lesson happens.
 * Before TZ-1 the wizard's `$timezone` property was writable by the
 * client (only the `$timezonePinned` flag beside it was `#[Locked]`),
 * and the API took the request's timezone in preference to the
 * account's own.
 *
 * The availability engine is a backstop, not the fix: it re-validates
 * the resulting instant, so a forged timezone could never book an
 * unavailable slot. What it could do is book a DIFFERENT real slot from
 * the one the student was shown, and stamp a false origin snapshot on
 * the record. Both are closed here.
 */
class BookingTimezoneTrustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo', 'duration_minutes' => 30, 'sort_order' => 1]);
    }

    private function platformDefault(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function teacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved', 'profile_visibility' => 'public']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        TeacherAvailability::factory()
            ->state(['teacher_id' => $teacher->id, 'timezone' => 'UTC'])
            ->forDay(Weekday::Monday)
            ->between('00:00:00', '23:30:00')
            ->create();

        return $teacher;
    }

    /** @param array<string, mixed> $profile */
    private function student(array $profile): User
    {
        $student = User::factory()->activeStudent()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        UserProfile::updateOrCreate(['user_id' => $student->id], $profile);

        return $student->fresh();
    }

    // ── E. Booking Wizard ───────────────────────────────────────────────

    public function test_wizard_resolves_the_students_own_timezone_on_mount(): void
    {
        $student = $this->student(['timezone' => 'Asia/Kolkata']);

        Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->assertSet('timezone', 'Asia/Kolkata')
            ->assertSet('timezonePinned', true);
    }

    public function test_a_forged_timezone_property_update_is_rejected_outright(): void
    {
        $student = $this->student(['timezone' => 'Asia/Kolkata']);

        // #[Locked] makes a client-side property write a hard error
        // rather than a silent overwrite — the strongest possible
        // outcome, and the difference from before TZ-1, when this exact
        // call quietly succeeded and moved the booking.
        $component = Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->assertSet('timezone', 'Asia/Kolkata');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $component->set('timezone', 'America/New_York');
    }

    public function test_browser_detection_cannot_override_an_explicit_profile_timezone(): void
    {
        $student = $this->student(['timezone' => 'Asia/Kolkata']);

        Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->call('setTimezone', 'America/New_York')
            ->assertSet('timezone', 'Asia/Kolkata');
    }

    public function test_browser_detection_still_fills_in_for_a_student_with_no_explicit_timezone(): void
    {
        // The legacy affordance is deliberately preserved: an account
        // that has never stated a timezone still benefits from the
        // device's, because the server has nothing better to offer.
        $this->platformDefault('Europe/Lisbon');
        $student = $this->student(['timezone' => null, 'country_id' => null]);

        Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->assertSet('timezone', 'Europe/Lisbon')
            ->assertSet('timezonePinned', false)
            ->call('setTimezone', 'America/New_York')
            ->assertSet('timezone', 'America/New_York');
    }

    public function test_a_country_derived_timezone_does_not_pin_because_the_student_never_chose_it(): void
    {
        // For a multi-timezone country the Country default may well be
        // the wrong zone, so the device is allowed to refine it. Only a
        // choice the student made themselves is protected.
        $country = Country::factory()->create(['default_timezone' => 'America/New_York']);
        $student = $this->student(['timezone' => null, 'country_id' => $country->id]);

        Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->assertSet('timezone', 'America/New_York')
            ->assertSet('timezonePinned', false)
            ->call('setTimezone', 'America/Los_Angeles')
            ->assertSet('timezone', 'America/Los_Angeles');
    }

    public function test_an_invalid_browser_timezone_is_ignored_and_the_resolved_value_stands(): void
    {
        $this->platformDefault('Europe/Lisbon');
        $student = $this->student(['timezone' => null, 'country_id' => null]);

        $component = Livewire::actingAs($student)->test(BookingWizard::class);

        foreach (['EST', '+05:30', 'Not/AZone', ''] as $bogus) {
            $component->call('setTimezone', $bogus)->assertSet('timezone', 'Europe/Lisbon');
        }
    }

    public function test_browser_detection_never_persists_to_the_profile(): void
    {
        $this->platformDefault('Europe/Lisbon');
        $student = $this->student(['timezone' => null, 'country_id' => null]);

        Livewire::actingAs($student)
            ->test(BookingWizard::class)
            ->call('setTimezone', 'America/New_York');

        $this->assertNull($student->fresh()->profile->timezone);
    }

    // ── F. Student Booking API ──────────────────────────────────────────

    public function test_a_conflicting_request_timezone_cannot_reinterpret_a_naive_instant(): void
    {
        $student = $this->student(['timezone' => 'Asia/Kolkata']);
        $teacher = $this->teacher();
        $monday = $this->nextMonday();

        $response = $this->actingAs($student)->postJson(route('dashboard.bookings.store'), [
            'type' => 'free_demo',
            'teacher_id' => $teacher->id,
            // Naive wall-clock. Read as Asia/Kolkata this is 03:30 UTC;
            // read as America/New_York it would be 13:00 UTC — a
            // different lesson, nine and a half hours away.
            'starts_at' => $monday->format('Y-m-d').' 09:00:00',
            'timezone' => 'America/New_York',
            'subject' => 'maths',
            'grade' => 8,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'starts_at' => $monday->setTime(9, 0)->shiftTimezone('Asia/Kolkata')->utc()->format('Y-m-d H:i:s'),
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    public function test_an_omitted_request_timezone_uses_the_students_own(): void
    {
        $student = $this->student(['timezone' => 'Asia/Kolkata']);
        $teacher = $this->teacher();
        $monday = $this->nextMonday();

        $this->actingAs($student)->postJson(route('dashboard.bookings.store'), [
            'type' => 'free_demo',
            'teacher_id' => $teacher->id,
            'starts_at' => $monday->format('Y-m-d').' 09:00:00',
            'subject' => 'maths',
            'grade' => 8,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'starts_at' => $monday->setTime(9, 0)->shiftTimezone('Asia/Kolkata')->utc()->format('Y-m-d H:i:s'),
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    public function test_a_request_timezone_is_still_honoured_when_the_account_has_none(): void
    {
        // The field keeps its meaning in the one case where the server
        // genuinely has nothing better — an account that has never
        // stated a timezone. A validated client value beats falling
        // through to the platform default.
        $this->platformDefault('Europe/Lisbon');
        $student = $this->student(['timezone' => null, 'country_id' => null]);
        $teacher = $this->teacher();
        $monday = $this->nextMonday();

        $this->actingAs($student)->postJson(route('dashboard.bookings.store'), [
            'type' => 'free_demo',
            'teacher_id' => $teacher->id,
            'starts_at' => $monday->format('Y-m-d').' 09:00:00',
            'timezone' => 'Asia/Kolkata',
            'subject' => 'maths',
            'grade' => 8,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'starts_at' => $monday->setTime(9, 0)->shiftTimezone('Asia/Kolkata')->utc()->format('Y-m-d H:i:s'),
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    public function test_an_offset_bearing_instant_is_unambiguous_regardless_of_the_timezone_field(): void
    {
        // A machine timestamp already fixes the instant. Both PHP and
        // Carbon honour the embedded offset over any timezone argument,
        // so this request means the same thing no matter what the
        // resolver returns.
        $student = $this->student(['timezone' => 'Asia/Kolkata']);
        $teacher = $this->teacher();
        $monday = $this->nextMonday();

        $this->actingAs($student)->postJson(route('dashboard.bookings.store'), [
            'type' => 'free_demo',
            'teacher_id' => $teacher->id,
            'starts_at' => $monday->format('Y-m-d').'T09:00:00+05:30',
            'timezone' => 'America/New_York',
            'subject' => 'maths',
            'grade' => 8,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'starts_at' => $monday->setTime(3, 30)->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_a_non_canonical_request_timezone_is_rejected_by_validation(): void
    {
        $student = $this->student(['timezone' => null, 'country_id' => null]);
        $teacher = $this->teacher();
        $monday = $this->nextMonday();

        foreach (['EST', '+05:30', 'US/Eastern', 'nonsense'] as $rejected) {
            $this->actingAs($student)->postJson(route('dashboard.bookings.store'), [
                'type' => 'free_demo',
                'teacher_id' => $teacher->id,
                'starts_at' => $monday->format('Y-m-d').' 09:00:00',
                'timezone' => $rejected,
                'subject' => 'maths',
                'grade' => 8,
            ])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        }
    }

    /** A Monday comfortably in the future, in UTC, so fixtures never race "after:now". */
    private function nextMonday(): CarbonImmutable
    {
        $date = CarbonImmutable::now('UTC')->addWeek()->startOfDay();

        while ($date->dayOfWeek !== Weekday::Monday->value) {
            $date = $date->addDay();
        }

        return $date;
    }
}
