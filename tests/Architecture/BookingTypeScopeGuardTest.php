<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Registry\BookingTypeRegistry;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A permanent guard against the removed Counselling/Parent-Meeting/
 * Webinar booking types and the shared-slot group-capacity mechanism
 * being reintroduced. Prefers structural checks
 * (class/column/method existence, registry contents) over free-text
 * grepping — CMS/content copy is free to use words like "counselling"
 * as long as it never becomes a bookable type key.
 */
class BookingTypeScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    // ── Removed booking-type drivers no longer exist ─────────────────────

    public function test_counselling_type_driver_does_not_exist(): void
    {
        $this->assertFalse(class_exists('App\Booking\Types\CounsellingType'));
    }

    public function test_parent_meeting_type_driver_does_not_exist(): void
    {
        $this->assertFalse(class_exists('App\Booking\Types\ParentMeetingType'));
    }

    public function test_webinar_type_driver_does_not_exist(): void
    {
        $this->assertFalse(class_exists('App\Booking\Types\WebinarType'));
    }

    public function test_no_removed_booking_type_source_files_exist_on_disk(): void
    {
        foreach ([
            'app/Booking/Types/CounsellingType.php',
            'app/Booking/Types/ParentMeetingType.php',
            'app/Booking/Types/WebinarType.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "{$path} must not exist — removed.");
        }
    }

    // ── Registry and seeded rows are exactly the Version 1 set ───────────

    public function test_registry_contains_exactly_the_approved_version_1_types(): void
    {
        $keys = collect(app(BookingTypeRegistry::class)->all())->map(fn ($driver) => $driver->key())->sort()->values()->all();

        $this->assertSame(['free_demo', 'paid_one_to_one'], $keys);
    }

    public function test_seeded_booking_types_are_exactly_the_approved_version_1_set(): void
    {
        $this->artisan('db:seed', ['--class' => 'BookingTypeSeeder', '--force' => true]);

        $keys = BookingType::query()->pluck('key')->sort()->values()->all();

        $this->assertSame(['free_demo', 'paid_one_to_one'], $keys);
    }

    public function test_removed_type_keys_are_not_present_after_seeding(): void
    {
        $this->artisan('db:seed', ['--class' => 'BookingTypeSeeder', '--force' => true]);

        foreach (['counselling', 'parent_meeting', 'webinar'] as $removedKey) {
            $this->assertDatabaseMissing('booking_types', ['key' => $removedKey]);
        }
    }

    // ── No group-capacity / shared-slot mechanism ─────────────────────────

    public function test_booking_types_table_has_no_capacity_column(): void
    {
        $this->assertFalse(Schema::hasColumn('booking_types', 'max_attendees'));
    }

    public function test_booking_type_interface_has_no_max_attendees_method(): void
    {
        $this->assertFalse(method_exists('App\Booking\Contracts\BookingTypeInterface', 'maxAttendees'));
    }

    public function test_booking_repository_has_no_shared_slot_or_capacity_methods(): void
    {
        $this->assertFalse(method_exists(BookingRepositoryInterface::class, 'studentCountForSlot'));

        $hasOverlap = new \ReflectionMethod(BookingRepositoryInterface::class, 'hasOverlap');
        $params = array_map(fn ($p) => $p->getName(), $hasOverlap->getParameters());

        $this->assertNotContains('sharedSlotTypeKey', $params);
    }

    public function test_availability_service_has_no_shared_slot_parameter(): void
    {
        $ensureAvailable = new \ReflectionMethod('App\Booking\Contracts\AvailabilityServiceInterface', 'ensureAvailable');
        $params = array_map(fn ($p) => $p->getName(), $ensureAvailable->getParameters());

        $this->assertNotContains('sharedSlotTypeKey', $params);
    }

    public function test_booking_service_has_no_shared_slot_key_or_assert_capacity_methods(): void
    {
        $this->assertFalse(method_exists('App\Booking\Services\BookingService', 'sharedSlotKey'));
        $this->assertFalse(method_exists('App\Booking\Services\BookingService', 'assertCapacity'));
    }

    public function test_time_slot_data_has_no_remaining_capacity_field(): void
    {
        $this->assertFalse(property_exists('App\Booking\DTOs\TimeSlotData', 'remainingCapacity'));
    }

    // ── Two students can never share an instructor+exact-time slot ───────

    public function test_two_students_cannot_book_the_same_instructor_and_exact_time(): void
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }
        BookingType::query()->firstOrCreate(['key' => 'free_demo'], ['name' => 'Free Demo', 'duration_minutes' => 30, 'is_active' => true]);

        $studentA = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $studentB = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $startsAt = now('UTC')->addDays(3)->setTime(10, 0)->toImmutable();

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $studentA->id,
            instructorId: $teacher->id,
            startsAt: $startsAt,
            durationMinutes: 30,
        ));

        $this->expectException(SlotUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $studentB->id,
            instructorId: $teacher->id,
            startsAt: $startsAt,
            durationMinutes: 30,
        ));
    }

    // ── Recurrence is a real, typed capability, not a weekly-only hack ───

    public function test_recurrence_frequency_supports_daily_and_weekly(): void
    {
        $cases = array_map(fn ($case) => $case->value, RecurrenceFrequency::cases());

        $this->assertSame(['daily', 'weekly'], $cases);
    }

    public function test_wizard_booking_service_supports_recurring_bookings(): void
    {
        $this->assertTrue(method_exists(WizardBookingServiceInterface::class, 'bookRecurring'));
    }
}
