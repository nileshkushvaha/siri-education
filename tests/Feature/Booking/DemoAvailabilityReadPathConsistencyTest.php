<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorService;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24B.1 — corrective closure of the two read-path inconsistencies
 * documented in the Phase 24B final report: InstructorService::card()
 * and StudentBookingService::availableTeachers() must both respect the
 * same effective rule (booking type active AND demo_lessons_enabled) as
 * the authoritative Phase 24B booking-creation rejection.
 */
class DemoAvailabilityReadPathConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->demoType = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
    }

    private function disableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = false;
        $settings->save();
    }

    private function enableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = true;
        $settings->save();
    }

    private function makeTeacher(string $subject = 'maths'): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
            'offers_demo' => true,
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject($subject, 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        return $teacher;
    }

    // ── 1 & 2. card() reflects the global toggle ────────────────────────────

    public function test_card_returns_offers_demo_true_when_enabled(): void
    {
        $teacher = $this->makeTeacher();

        $card = app(InstructorService::class)->card($teacher);

        $this->assertTrue($card['offers_demo']);
    }

    public function test_card_returns_offers_demo_false_when_disabled(): void
    {
        $teacher = $this->makeTeacher();
        $this->disableDemos();

        $card = app(InstructorService::class)->card($teacher);

        $this->assertFalse($card['offers_demo']);
    }

    // ── 3. Disabling demos does not remove the instructor from discovery ──

    public function test_disabling_demos_does_not_remove_the_instructor_from_discovery(): void
    {
        $teacher = $this->makeTeacher();
        $this->disableDemos();

        $results = app(InstructorService::class)->listing(Request::create('/instructors'));

        $this->assertTrue($results->getCollection()->contains('id', $teacher->id));
    }

    // ── 4. No other card data changes ───────────────────────────────────────

    public function test_only_offers_demo_changes_between_enabled_and_disabled_card_snapshots(): void
    {
        $teacher = $this->makeTeacher();

        $enabled = app(InstructorService::class)->card($teacher);
        $this->disableDemos();
        $disabled = app(InstructorService::class)->card($teacher);

        $this->assertTrue($enabled['offers_demo']);
        $this->assertFalse($disabled['offers_demo']);

        unset($enabled['offers_demo'], $disabled['offers_demo']);
        $this->assertEquals($enabled, $disabled);
    }

    // ── 5 & 6. availableTeachers('free_demo', ...) reflects the toggle ─────

    public function test_free_demo_teacher_lookup_returns_empty_when_disabled(): void
    {
        $teacher = $this->makeTeacher();
        $this->disableDemos();

        $result = app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5);

        $this->assertCount(0, $result);
        $this->assertFalse($result->contains('id', $teacher->id));
    }

    public function test_free_demo_teacher_lookup_behaves_normally_when_enabled(): void
    {
        $teacher = $this->makeTeacher();

        $result = app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5);

        $this->assertTrue($result->contains('id', $teacher->id));
    }

    // ── 7. Paid teacher lookup is unaffected while demos are disabled ──────

    public function test_paid_teacher_lookup_is_unaffected_while_demos_are_disabled(): void
    {
        $teacher = $this->makeTeacher();
        BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $this->disableDemos();

        $result = app(StudentBookingServiceInterface::class)->availableTeachers('paid_one_to_one', 'maths', 5);

        $this->assertTrue($result->contains('id', $teacher->id));
    }

    // ── 8. Re-enabling restores correct responses for both read paths ──────

    public function test_re_enabling_restores_card_and_teacher_lookup_responses(): void
    {
        $teacher = $this->makeTeacher();
        $this->disableDemos();

        $this->assertFalse(app(InstructorService::class)->card($teacher)['offers_demo']);
        $this->assertCount(0, app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5));

        $this->enableDemos();

        $this->assertTrue(app(InstructorService::class)->card($teacher)['offers_demo']);
        $this->assertTrue(
            app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5)->contains('id', $teacher->id),
        );
    }

    // ── 9. Booking-type deactivation and the global setting combine correctly ─

    public function test_deactivating_the_free_demo_type_also_hides_demo_availability_on_the_card(): void
    {
        $teacher = $this->makeTeacher();
        $this->demoType->update(['is_active' => false]);

        $card = app(InstructorService::class)->card($teacher);

        $this->assertFalse($card['offers_demo']);
        // The global toggle itself was never touched by deactivating the type.
        $this->assertTrue(app(FeatureSettings::class)->demo_lessons_enabled);
    }

    public function test_deactivating_the_free_demo_type_rejects_the_teacher_lookup_as_before(): void
    {
        $this->makeTeacher();
        $this->demoType->update(['is_active' => false]);

        // Unchanged pre-existing behavior: requireActiveByKey() throws
        // before this phase's new check is ever reached.
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Booking type "free_demo" is not currently accepting bookings.');

        app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5);
    }

    // ── 10. The Phase 24B authoritative rejection is unaffected ────────────

    public function test_authoritative_booking_service_still_rejects_demo_creation_when_disabled(): void
    {
        $teacher = $this->makeTeacher();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]); // Phase 24H.1A: booking requires an Active student
        $this->disableDemos();

        $this->expectException(FreeDemoUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));
    }
}
