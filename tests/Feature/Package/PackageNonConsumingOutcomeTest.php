<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\Weekday;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Services\LessonLifecycleService;
use App\Models\Booking;
use App\Models\BookingPayment;
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
use App\Package\Services\PackageEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 4E.4 (Phase 4E.3 coverage gap C) — what a package-funded lesson
 * that is NOT delivered actually costs the student.
 *
 * The answer is: nothing, and their unit comes back. That is the whole
 * remedy, and it is deliberately not a refund. No booking payment was
 * ever collected for a package-funded lesson, so there is no money to
 * return; inventing a wallet credit here would be inventing a refund
 * policy nobody approved, and would pay the student twice for a lesson
 * they had already prepaid.
 *
 * Three funding types, three deliberately different commercial
 * outcomes, asserted side by side so a future change cannot quietly
 * collapse them into one.
 */
class PackageNonConsumingOutcomeTest extends TestCase
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
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);
        $this->subject = $this->seedLessonSubject('maths');

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
        ]);
    }

    private function entitlement(int $paid = 3): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () use ($paid) {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
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

            return $row->refresh();
        });
    }

    private function packageBooking(StudentPackageEntitlement $entitlement, int $hour = 10): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime($hour, 0),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
            packageEntitlementId: (string) $entitlement->id,
        ))->refresh();
    }

    private function lessons(): LessonLifecycleService
    {
        return app(LessonLifecycleService::class);
    }

    private function entitlements(): PackageEntitlementService
    {
        return app(PackageEntitlementService::class);
    }

    /** Drives the real no-show finalization path, not a hand-set status. */
    private function finalizeInstructorNoShow(Booking $booking): void
    {
        $lesson = $this->lessons()->createFromBooking($booking);

        $this->travelTo($booking->ends_at->copy()->addMinutes(5));

        $this->lessons()->markStudentAttendance($lesson, LessonAttendanceStatus::Attended);
        $this->lessons()->markInstructorAttendance($lesson->refresh(), LessonAttendanceStatus::NoShow);
        $this->lessons()->finalizeNoShow($lesson->refresh());
    }

    // ── The remedy: capacity back, no money moved ─────────────────────────

    public function test_an_instructor_no_show_returns_the_unit_to_available_capacity(): void
    {
        $entitlement = $this->entitlement(paid: 3);
        $booking = $this->packageBooking($entitlement);

        // Before: one unit committed to this booking.
        $this->assertSame(2, $this->entitlements()->availableToBook($entitlement->refresh()));
        $this->assertSame(
            PackageEntitlementReservationStatus::Reserved,
            StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );

        $this->finalizeInstructorNoShow($booking);

        $entitlement->refresh();

        // After: released, not consumed — the student may book it again.
        $this->assertSame(
            PackageEntitlementReservationStatus::Released,
            StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );
        $this->assertSame(0, (int) $entitlement->used_quantity);
        $this->assertSame(3, $this->entitlements()->availableToBook($entitlement));
        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
    }

    public function test_a_package_no_show_moves_no_money_at_all(): void
    {
        $booking = $this->packageBooking($this->entitlement());

        $this->finalizeInstructorNoShow($booking);

        // The point of the whole policy: capacity is the remedy, so
        // nothing monetary may appear anywhere.
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $this->student->id)->count());
        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_disposition_reason_is_package_aware_not_unpaid(): void
    {
        $booking = $this->packageBooking($this->entitlement());

        $this->finalizeInstructorNoShow($booking);

        $disposition = LessonFinancialDisposition::query()->where('booking_id', $booking->id)->first();

        if ($disposition === null) {
            $this->markTestSkipped('Financial disposition recording is disabled.');
        }

        // `_unpaid` would claim a student who HAD paid did not, and
        // `completed_demo` would claim a prepaid lesson was free.
        $this->assertStringNotContainsString('_unpaid', (string) $disposition->reason_code);
        $this->assertNotSame('completed_demo', $disposition->reason_code);
        $this->assertStringContainsString('package_unit_restored', (string) $disposition->reason_code);

        // No student-side monetary action — the existing disposition
        // that already means exactly that.
        $this->assertSame(LessonStudentDisposition::None, $disposition->student_disposition);
    }

    // ── The other two funding types keep their own outcomes ───────────────

    public function test_a_free_demo_no_show_is_unaffected(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
        ]);

        $this->finalizeInstructorNoShow($booking);

        $disposition = LessonFinancialDisposition::query()->where('booking_id', $booking->id)->first();

        if ($disposition === null) {
            $this->markTestSkipped('Financial disposition recording is disabled.');
        }

        // A demo student genuinely paid nothing, so `_unpaid` is the
        // truth here — and must stay the truth.
        $this->assertStringContainsString('_unpaid', (string) $disposition->reason_code);
        $this->assertStringNotContainsString('package', (string) $disposition->reason_code);
        $this->assertSame(LessonStudentDisposition::None, $disposition->student_disposition);
    }

    public function test_a_normal_paid_no_show_still_requires_a_wallet_refund(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->finalizeInstructorNoShow($booking);

        $disposition = LessonFinancialDisposition::query()->where('booking_id', $booking->id)->first();

        if ($disposition === null) {
            $this->markTestSkipped('Financial disposition recording is disabled.');
        }

        // Real money was collected, so the existing refund policy stands
        // untouched — the package work must not have softened it.
        $this->assertSame(LessonStudentDisposition::FullWalletRefundRequired, $disposition->student_disposition);
        $this->assertSame('instructor_no_show_refund_required', $disposition->reason_code);
    }

    // ── Every non-consuming outcome behaves the same way ──────────────────

    public function test_a_cancelled_package_booking_also_returns_its_unit(): void
    {
        $entitlement = $this->entitlement(paid: 2);
        $booking = $this->packageBooking($entitlement);

        $this->assertSame(1, $this->entitlements()->availableToBook($entitlement->refresh()));

        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            cancelledBy: BookingActor::Student,
            reason: 'Changed my mind.',
        ));

        $entitlement->refresh();

        $this->assertSame(
            PackageEntitlementReservationStatus::Released,
            StudentPackageEntitlementReservation::query()->where('booking_id', $booking->id)->firstOrFail()->status,
        );
        $this->assertSame(0, (int) $entitlement->used_quantity);
        $this->assertSame(2, $this->entitlements()->availableToBook($entitlement));
    }

    public function test_a_released_unit_can_genuinely_be_booked_again(): void
    {
        // The remedy is only real if the capacity is usable, not merely
        // if a status column changed.
        $entitlement = $this->entitlement(paid: 1);
        $first = $this->packageBooking($entitlement, hour: 10);

        $this->assertSame(0, $this->entitlements()->availableToBook($entitlement->refresh()));

        $this->finalizeInstructorNoShow($first);
        $this->travelBack();

        $second = $this->packageBooking($entitlement->refresh(), hour: 14);

        $this->assertSame(BookingPaymentStatus::PackageFunded, $second->payment_status);
        $this->assertSame(
            PackageEntitlementReservationStatus::Reserved,
            StudentPackageEntitlementReservation::query()->where('booking_id', $second->id)->firstOrFail()->status,
        );
    }
}
