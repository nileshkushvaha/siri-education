<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingService;
use App\Booking\Services\WizardBookingService;
use App\Http\Resources\Student\StudentBookingResource;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementReservation;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletLedgerEntry;
use App\Package\Enums\PackageEntitlementReservationStatus;
use App\Package\Enums\PackageEntitlementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 4E.3 — the downstream semantics of "package-funded".
 *
 * Every finding closed here has the same root shape: a domain asked a
 * question about money and answered it with `=== Paid`, so a prepaid
 * package lesson fell through. The fixes are semantic, not structural —
 * what changes is WHICH question each call site asks, never how
 * packages work.
 *
 * Reuses PackageFundedDeliveryIntegrationTest's fixture approach: real
 * bookings from the real service, never hand-built rows.
 */
class PackageDownstreamSemanticsTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->profile()->update([
            'phone_e164' => '+9199999'.str_pad((string) $this->student->id, 5, '0', STR_PAD_LEFT),
            'phone_verified_at' => now(),
        ]);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->instructor->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->instructor->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $this->instructor->id])
                ->forDay($day)
                ->between('09:00:00', '23:59:00')
                ->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);
        $this->subject = $this->seedLessonSubject('maths');

        $this->setFinancialSettings(['earnings_enabled' => true]);
    }

    private function studentUser(): User
    {
        return $this->student;
    }

    private function instructorId(): int
    {
        return (int) $this->instructor->id;
    }

    private function slot(int $daysAhead = 3, int $hour = 10): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime($hour, 0);
    }

    private function activeEntitlement(int $paid = 3): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () use ($paid) {
            Schema::disableForeignKeyConstraints();

            $entitlement = StudentPackageEntitlement::query()->create([
                'student_id' => $this->student->id,
                'instructor_id' => $this->instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $this->subject->id,
                'paid_quantity' => $paid,
                'bonus_quantity' => 0,
                'total_quantity' => $paid,
                'used_quantity' => 0,
                'status' => PackageEntitlementStatus::Active,
                'validity_days' => 365,
                'activated_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
            ]);

            Schema::enableForeignKeyConstraints();

            return $entitlement->refresh();
        });
    }

    private function requestPackageFundedBooking(StudentPackageEntitlement $entitlement): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
            packageEntitlementId: (string) $entitlement->id,
        ))->refresh();
    }

    private function requestOrdinaryPaidBooking(): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: $this->slot(hour: 12),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
        ))->refresh();
    }

    // ── PKG-AUD-007. Recurring must refuse, never silently discard ────────

    public function test_a_recurring_request_carrying_a_package_is_refused_outright(): void
    {
        $entitlement = $this->activeEntitlement();

        $data = new WizardBookingData(
            typeKey: 'paid_one_to_one',
            subject: 'maths',
            grade: 7,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            timezone: 'UTC',
            teacherId: $this->instructorId(),
            packageEntitlementId: (string) $entitlement->id,
        );

        $this->actingAs($this->studentUser());

        try {
            app(WizardBookingService::class)->bookRecurring($data, $this->recurrence());
            $this->fail('A recurring package-funded request must be refused.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('one at a time', $e->getMessage());
        }

        // Zero side effects: the refusal happens before any occurrence
        // is attempted, so nothing partial is left behind.
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, StudentPackageEntitlementReservation::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, BookingPayment::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
        $this->assertSame(0, (int) $entitlement->refresh()->used_quantity);
    }

    public function test_a_normal_recurring_paid_booking_is_unaffected(): void
    {
        $data = new WizardBookingData(
            typeKey: 'paid_one_to_one',
            subject: 'maths',
            grade: 7,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            timezone: 'UTC',
            teacherId: $this->instructorId(),
        );

        $this->actingAs($this->studentUser());

        $result = app(WizardBookingService::class)->bookRecurring($data, $this->recurrence());

        // Recurring without a package keeps working exactly as before.
        $this->assertGreaterThan(1, $result->booked->count());

        foreach ($result->booked as $booking) {
            $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
            $this->assertNull($booking->package_entitlement_id);
        }
    }

    // ── PKG-AUD-008. Reschedule may not outrun package validity ───────────

    public function test_a_package_lesson_may_be_rescheduled_inside_validity(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        $moved = app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $this->slot(daysAhead: 5, hour: 11),
            actor: BookingActor::Student,
        ));

        $this->assertTrue($moved->starts_at->equalTo($this->slot(daysAhead: 5, hour: 11)));
    }

    public function test_a_package_lesson_cannot_be_rescheduled_past_expiry(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        // The package now lapses well before the proposed new slot.
        $entitlement->forceFill(['expires_at' => CarbonImmutable::now('UTC')->addDays(4)])->save();

        $originalStartsAt = $booking->starts_at;

        try {
            app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
                startsAt: $this->slot(daysAhead: 10, hour: 11),
                actor: BookingActor::Student,
            ));
            $this->fail('A reschedule past package expiry must be refused.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('expires', $e->getMessage());
        }

        $booking->refresh();

        // Nothing moved and nothing was released: a refused reschedule
        // must leave schedule, reservation and balance untouched.
        $this->assertTrue($booking->starts_at->equalTo($originalStartsAt));
        $this->assertSame(
            PackageEntitlementReservationStatus::Reserved,
            StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );
        $this->assertSame(1, StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, (int) $entitlement->refresh()->used_quantity);
    }

    public function test_a_lesson_finishing_exactly_at_expiry_is_allowed(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        $newStart = $this->slot(daysAhead: 5, hour: 11);
        // Inclusive boundary: "valid until" includes the instant itself,
        // matching the booking-time slot filter's <= comparison.
        $entitlement->forceFill(['expires_at' => $newStart->addMinutes(60)])->save();

        $moved = app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $newStart,
            actor: BookingActor::Student,
        ));

        $this->assertTrue($moved->starts_at->equalTo($newStart));
    }

    public function test_a_package_with_no_expiry_imposes_no_reschedule_limit(): void
    {
        $entitlement = $this->activeEntitlement();
        $entitlement->forceFill(['expires_at' => null, 'validity_days' => null])->save();

        $booking = $this->requestPackageFundedBooking($entitlement->refresh());

        $moved = app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $this->slot(daysAhead: 20, hour: 11),
            actor: BookingActor::Student,
        ));

        $this->assertTrue($moved->starts_at->equalTo($this->slot(daysAhead: 20, hour: 11)));
    }

    public function test_expiry_is_compared_as_an_absolute_instant_regardless_of_timezone(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        // 23:30 UTC start + 60 minutes finishes at 00:30 the NEXT day —
        // past a midnight expiry, even though the local wall clock on
        // the start date still reads "before expiry". Display timezone
        // must not rescue it.
        $expiry = CarbonImmutable::now('UTC')->addDays(6)->setTime(0, 0);
        $entitlement->forceFill(['expires_at' => $expiry])->save();

        $this->expectException(BookingException::class);

        app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: CarbonImmutable::now('UTC')->addDays(5)->setTime(23, 30),
            actor: BookingActor::Student,
            durationMinutes: 60,
        ));
    }

    public function test_an_ordinary_paid_booking_reschedule_is_unaffected_by_package_rules(): void
    {
        $booking = $this->requestOrdinaryPaidBooking();
        $booking->forceFill([
            'payment_status' => BookingPaymentStatus::Paid,
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();

        $moved = app(BookingService::class)->reschedule($booking->refresh(), new RescheduleBookingData(
            startsAt: $this->slot(daysAhead: 30, hour: 11),
            actor: BookingActor::Student,
        ));

        $this->assertTrue($moved->starts_at->equalTo($this->slot(daysAhead: 30, hour: 11)));
    }

    // ── PKG-AUD-011. The student is not asked to pay twice ────────────────

    public function test_a_package_funded_booking_does_not_ask_for_payment(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());

        $payload = (new StudentBookingResource($booking))->toArray(Request::create('/'));

        $this->assertFalse($payload['requires_payment']);
        // The real commercial value is still exposed — prepaid, not free.
        $this->assertNotNull($payload['price']);
        $this->assertSame('Covered by Package', $payload['payment_status_label']);
    }

    public function test_requires_payment_still_reflects_every_other_status(): void
    {
        $this->assertTrue(BookingPaymentStatus::Pending->isPayable());
        $this->assertTrue(BookingPaymentStatus::Failed->isPayable());

        $this->assertFalse(BookingPaymentStatus::Paid->isPayable());
        $this->assertFalse(BookingPaymentStatus::NotRequired->isPayable());
        $this->assertFalse(BookingPaymentStatus::PackageFunded->isPayable());
    }

    public function test_package_funded_is_never_labelled_free(): void
    {
        $this->assertNotSame(
            BookingPaymentStatus::NotRequired->label(),
            BookingPaymentStatus::PackageFunded->label(),
        );
    }

    // ── Enum query helpers stay in step with their predicates ─────────────

    public function test_the_settled_query_helper_matches_its_predicate(): void
    {
        foreach (BookingPaymentStatus::cases() as $status) {
            $this->assertSame(
                $status->isSettled(),
                in_array($status, BookingPaymentStatus::settled(), strict: true),
                sprintf('%s disagrees between isSettled() and settled().', $status->name),
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function recurrence(): RecurrenceData
    {
        return new RecurrenceData(occurrences: 3, frequency: RecurrenceFrequency::Weekly);
    }
}
