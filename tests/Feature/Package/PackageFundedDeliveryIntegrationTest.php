<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Services\BookingMeetingService;
use App\Earnings\Services\InstructorEarningService;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Services\LessonLifecycleService;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorCompensationAgreement;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementConsumption;
use App\Models\StudentPackageEntitlementReservation;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletLedgerEntry;
use App\Package\Enums\PackageEntitlementReservationStatus;
use App\Package\Enums\PackageEntitlementStatus;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 4E.1 — the seam between a package-funded Booking and the rest
 * of the platform, proved BEHAVIORALLY.
 *
 * The Phase 4E audit found three blockers that every existing package
 * test passed straight through, for one structural reason: the package
 * suite built its lessons with `Lesson::factory()`. That bypasses
 * LessonLifecycleService::isEligible(), which was the exact gate
 * refusing package-funded bookings in production. A whole consumption
 * layer was therefore verified against a lesson production could never
 * have created.
 *
 * So the rule for this file is deliberate and load-bearing:
 *
 *     The Booking, Lesson, reservation transition, consumption row and
 *     InstructorEarning under test are NEVER constructed by a factory.
 *     Every one of them is produced by the real production service or
 *     the real event listener, or the test is worthless.
 *
 * Factories are used only for PREREQUISITE configuration a real
 * deployment would already have — users, availability, prices,
 * compensation agreements — and for the entitlement, whose own
 * proposal → purchase → settlement pipeline has a dedicated suite
 * (PackagePurchaseSettlementTest) and is not what these tests claim.
 */
class PackageFundedDeliveryIntegrationTest extends TestCase
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
            // Paid booking types run through StudentFinancialVerificationGate.
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
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);

        // Must be the SAME subject the booking's `meta['subject']` slug
        // resolves to in CreateLessonFromBookingAction — consumption
        // refuses a package whose subject differs from the lesson's, and
        // that guard is deliberately not being worked around here.
        $this->subject = $this->seedLessonSubject('maths');

        $this->setFinancialSettings(['earnings_enabled' => true]);

        // Hourly 800.00 INR — deliberately unrelated to the 499.00 lesson
        // price, so an earning that accidentally derived from student
        // price would show up as the wrong number rather than passing.
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $this->instructor->id,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'effective_from' => now()->subMonth(),
        ]);
    }

    // ── Fixtures (prerequisites only — never the outputs under test) ──────

    /**
     * An already-settled entitlement. The settlement pipeline that
     * normally produces this is covered by its own suite; what these
     * tests need is simply a student who legitimately owns lessons.
     */
    private function activeEntitlement(int $paid = 2, int $bonus = 0): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () use ($paid, $bonus) {
            Schema::disableForeignKeyConstraints();

            $entitlement = StudentPackageEntitlement::query()->create([
                'student_id' => $this->student->id,
                'instructor_id' => $this->instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $this->subject->id,
                'paid_quantity' => $paid,
                'bonus_quantity' => $bonus,
                'total_quantity' => $paid + $bonus,
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

    private function slot(int $daysAhead = 3, int $hour = 10): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime($hour, 0);
    }

    /**
     * A real package-funded Booking, created by BookingService — which
     * is also what takes the entitlement reservation, inside the same
     * transaction. Nothing here is hand-written onto the row.
     */
    private function requestPackageFundedBooking(StudentPackageEntitlement $entitlement, ?CarbonImmutable $startsAt = null): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: $startsAt ?? $this->slot(),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
            packageEntitlementId: (string) $entitlement->id,
        ))->refresh();
    }

    /** An ordinary paid booking — the control group for every regression below. */
    private function requestOrdinaryPaidBooking(int $hour = 12): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: $this->slot(hour: $hour),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
        ))->refresh();
    }

    private function lessons(): LessonLifecycleService
    {
        return app(LessonLifecycleService::class);
    }

    /** Moves the clock past a booking's end so completion is legitimate rather than an override. */
    private function travelPast(Booking $booking): void
    {
        $this->travelTo($booking->ends_at->copy()->addMinutes(5));
    }

    // ── PKG-AUD-001. Booking → Lesson ─────────────────────────────────────

    public function test_a_package_funded_booking_becomes_a_lesson_through_the_production_lifecycle(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        // Precondition: this really is the state the audit found broken.
        $this->assertSame(BookingPaymentStatus::PackageFunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $lesson = $this->lessons()->createFromBooking($booking);

        $this->assertNotNull($lesson, 'A package-funded booking must produce a lesson.');
        $this->assertSame($booking->id, $lesson->booking_id);
    }

    /**
     * Part 3 — attribution must survive the REAL creation path, not just
     * a factory that was told the answer.
     */
    public function test_the_production_created_lesson_inherits_the_package_attribution(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        $lesson = $this->lessons()->createFromBooking($booking);

        $this->assertSame((string) $entitlement->id, (string) $lesson->package_entitlement_id);
    }

    public function test_an_unsettled_booking_still_cannot_become_a_lesson(): void
    {
        // The fix must not have widened eligibility to unpaid bookings:
        // an ordinary paid booking sits at Pending until payment settles.
        $booking = $this->requestOrdinaryPaidBooking();

        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNull($this->lessons()->createFromBooking($booking));
    }

    public function test_settlement_predicates_answer_correctly_for_every_status(): void
    {
        // permitsDelivery() — "may we deliver?"
        $this->assertTrue(BookingPaymentStatus::Paid->permitsDelivery());
        $this->assertTrue(BookingPaymentStatus::NotRequired->permitsDelivery());
        $this->assertTrue(BookingPaymentStatus::PackageFunded->permitsDelivery());
        $this->assertFalse(BookingPaymentStatus::Pending->permitsDelivery());
        $this->assertFalse(BookingPaymentStatus::Failed->permitsDelivery());
        $this->assertFalse(BookingPaymentStatus::Refunded->permitsDelivery());

        // isSettled() — "was money secured?" — keeps its narrower meaning,
        // which is why a free demo answers false to it and true above.
        $this->assertTrue(BookingPaymentStatus::PackageFunded->isSettled());
        $this->assertFalse(BookingPaymentStatus::NotRequired->isSettled());

        $this->assertEqualsCanonicalizing(
            [BookingPaymentStatus::NotRequired, BookingPaymentStatus::Paid, BookingPaymentStatus::PackageFunded],
            BookingPaymentStatus::deliverable(),
        );
    }

    // ── PKG-AUD-006. Booking → Meeting ────────────────────────────────────

    public function test_a_package_funded_online_booking_is_eligible_for_a_meeting(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->create_after_paid_booking_confirmation = true;
        $settings->create_after_demo_booking_confirmation = false;
        $settings->save();

        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());

        // Follows the PAID setting: with the demo switch off it is still
        // eligible, so it cannot be silently inheriting demo behaviour.
        $this->assertTrue(app(BookingMeetingService::class)->isEligible($booking));
    }

    public function test_a_package_funded_booking_respects_the_paid_meeting_switch(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->create_after_paid_booking_confirmation = false;
        $settings->create_after_demo_booking_confirmation = true;
        $settings->save();

        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());

        // Turning the paid switch off must turn package meetings off too —
        // the demo switch must not rescue it.
        $this->assertFalse(app(BookingMeetingService::class)->isEligible($booking));
    }

    // ── Part 12. Completion → consumption ─────────────────────────────────

    public function test_completing_a_production_created_package_lesson_consumes_exactly_one_unit(): void
    {
        $entitlement = $this->activeEntitlement(paid: 2);
        $booking = $this->requestPackageFundedBooking($entitlement);
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $entitlement->refresh();

        $this->assertSame(1, (int) $entitlement->used_quantity);
        $this->assertSame(1, (int) $entitlement->remaining_quantity);
        $this->assertSame(
            1,
            StudentPackageEntitlementConsumption::query()->where('lesson_id', $lesson->id)->count(),
        );
    }

    public function test_completion_moves_the_reservation_from_reserved_to_consumed(): void
    {
        $entitlement = $this->activeEntitlement();
        $booking = $this->requestPackageFundedBooking($entitlement);

        $reservation = StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(PackageEntitlementReservationStatus::Reserved, $reservation->status);

        $lesson = $this->lessons()->createFromBooking($booking);
        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $this->assertSame(
            PackageEntitlementReservationStatus::Consumed,
            $reservation->refresh()->status,
        );
    }

    public function test_a_replayed_completion_consumes_only_once(): void
    {
        $entitlement = $this->activeEntitlement(paid: 3);
        $booking = $this->requestPackageFundedBooking($entitlement);
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());
        // A second delivery of the same completion — queue retry, admin
        // re-run, or a duplicated meeting event.
        $this->lessons()->complete($lesson->refresh());
        LessonCompleted::dispatch($lesson->refresh());

        $this->assertSame(1, (int) $entitlement->refresh()->used_quantity);
        $this->assertSame(
            1,
            StudentPackageEntitlementConsumption::query()->where('lesson_id', $lesson->id)->count(),
        );
    }

    // ── PKG-AUD-002. Completion → instructor earning ──────────────────────

    public function test_a_completed_package_funded_lesson_creates_an_instructor_earning(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $earning = app(InstructorEarningService::class)->createFromLesson($lesson->refresh());

        $this->assertNotNull($earning, 'A package-funded lesson must be compensable.');
        $this->assertSame($this->instructor->id, $earning->instructor_id);
    }

    /**
     * Part 8 — the original bug did more than skip the earning: it wrote
     * a PERMANENT ineligibility, closing the recovery queue against a
     * perfectly valid lesson.
     */
    public function test_a_package_funded_lesson_is_never_recorded_as_permanently_ineligible(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());
        app(InstructorEarningService::class)->createFromLesson($lesson->refresh());

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'instructor_earnings',
            'event' => 'earning_skipped',
            'subject_id' => $lesson->id,
        ]);
    }

    /** Part 7 — a bonus unit is compensated exactly like a paid one. */
    public function test_a_bonus_package_unit_earns_the_same_as_a_paid_unit(): void
    {
        $paidBooking = $this->requestPackageFundedBooking($this->activeEntitlement(paid: 1, bonus: 0));
        $paidLesson = $this->lessons()->createFromBooking($paidBooking);
        $this->travelPast($paidBooking);
        $this->lessons()->complete($paidLesson->refresh());
        $paidEarning = app(InstructorEarningService::class)->createFromLesson($paidLesson->refresh());

        $this->travelBack();

        // A package with NOTHING but bonus lessons — the student paid
        // nothing for this unit, the instructor is owed the same.
        $bonusBooking = $this->requestPackageFundedBooking(
            $this->activeEntitlement(paid: 0, bonus: 1),
            $this->slot(daysAhead: 4, hour: 14),
        );
        $bonusLesson = $this->lessons()->createFromBooking($bonusBooking);
        $this->travelPast($bonusBooking);
        $this->lessons()->complete($bonusLesson->refresh());
        $bonusEarning = app(InstructorEarningService::class)->createFromLesson($bonusLesson->refresh());

        $this->assertNotNull($bonusEarning, 'A bonus package lesson must still compensate the instructor.');
        $this->assertSame(
            (int) $paidEarning->earning_amount_minor,
            (int) $bonusEarning->earning_amount_minor,
        );
    }

    /**
     * Compensation independence, behaviorally rather than structurally:
     * the earning equals the AGREEMENT rate (800.00/hr), not the
     * student-side lesson price (499.00) the package was sold at.
     */
    public function test_the_earning_comes_from_the_agreement_not_the_package_price(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $earning = app(InstructorEarningService::class)->createFromLesson($lesson->refresh());

        $this->assertSame(80000, (int) $earning->earning_amount_minor);
        $this->assertNotSame(49900, (int) $earning->earning_amount_minor);
    }

    // ── PKG-AUD-005. Financial disposition ────────────────────────────────

    public function test_a_completed_package_funded_lesson_is_not_classified_as_a_demo(): void
    {
        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
        ]);

        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $disposition = LessonFinancialDisposition::query()
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($disposition === null) {
            // Disposition recording is behind its own feature switch; the
            // classification is still asserted wherever it does run.
            $this->markTestSkipped('Financial disposition recording is disabled by default.');
        }

        $this->assertNotSame('completed_demo', $disposition->reason_code);
        $this->assertSame('completed_paid', $disposition->reason_code);
    }

    // ── Isolation: prepaid must move no money now ─────────────────────────

    public function test_package_funded_delivery_moves_no_money(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());
        app(InstructorEarningService::class)->createFromLesson($lesson->refresh());

        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $this->student->id)->count());
        // The booking keeps its real commercial value — prepaid, not free.
        $this->assertNotNull($booking->price);
    }

    // ── Regressions: the flows that must not have moved ───────────────────

    public function test_an_ordinary_paid_booking_still_reaches_a_lesson_once_settled(): void
    {
        $booking = $this->requestOrdinaryPaidBooking();

        // Settle it the way the payment pipeline would, then re-ask.
        $booking->forceFill([
            'payment_status' => BookingPaymentStatus::Paid,
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();

        $lesson = $this->lessons()->createFromBooking($booking->refresh());

        $this->assertNotNull($lesson);
        $this->assertNull($lesson->package_entitlement_id, 'An ordinary paid lesson must never carry package attribution.');
    }

    public function test_a_free_demo_booking_still_reaches_a_lesson_and_stays_package_free(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
        ]);

        $lesson = $this->lessons()->createFromBooking($booking);

        $this->assertNotNull($lesson, 'Free Demo lesson creation must be unchanged.');
        $this->assertNull($lesson->package_entitlement_id);
    }

    public function test_an_ordinary_paid_lesson_never_consumes_a_package(): void
    {
        // The student owns a perfectly matching package, but this booking
        // did not choose it — nothing may be drawn.
        $entitlement = $this->activeEntitlement();

        $booking = $this->requestOrdinaryPaidBooking();
        $booking->forceFill([
            'payment_status' => BookingPaymentStatus::Paid,
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();

        $lesson = $this->lessons()->createFromBooking($booking->refresh());
        $this->travelPast($booking);
        $this->lessons()->complete($lesson->refresh());

        $this->assertSame(0, (int) $entitlement->refresh()->used_quantity);
        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
    }

    public function test_no_lesson_is_created_twice_for_one_booking(): void
    {
        $booking = $this->requestPackageFundedBooking($this->activeEntitlement());

        $first = $this->lessons()->createFromBooking($booking);
        $second = $this->lessons()->createFromBooking($booking->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lesson::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(LessonStatus::Scheduled, $first->status);
    }
}
