<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingService;
use App\Messaging\Services\MessagingEligibilityService;
use App\Models\Booking;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Enums\PackagePurchaseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4E.4 — Phase 4E.3 coverage gaps B and D.
 *
 * D. PACKAGE EXPIRY ACROSS A DST TRANSITION
 *    Expiry is an absolute instant and a lesson's finish is an absolute
 *    instant. The failure mode being guarded against is someone
 *    "helpfully" comparing local wall-clock strings, which works all
 *    year and then silently shifts by an hour on one Sunday in spring —
 *    letting a lesson run past a package's paid validity, or refusing a
 *    lesson that is genuinely inside it.
 *
 * B. ACCESS SEMANTICS
 *    A package-funded booking is the same relationship evidence a paid
 *    booking is. Owning a package is not.
 */
class PackageExpiryAndAccessSemanticsTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    /** Europe/London springs forward 01:00 → 02:00 UTC on 29 March 2026. */
    private const string DST_ZONE = 'Europe/London';

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
                ->forDay($day)->between('00:00:00', '23:59:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);
        $this->subject = $this->seedLessonSubject('maths');
    }

    private function entitlement(?CarbonImmutable $expiresAt): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () use ($expiresAt) {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $this->student->id,
                'instructor_id' => $this->instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $this->subject->id,
                'paid_quantity' => 5,
                'bonus_quantity' => 0,
                'total_quantity' => 5,
                'used_quantity' => 0,
                'status' => PackageEntitlementStatus::Active,
                'validity_days' => $expiresAt === null ? null : 365,
                'activated_at' => now()->subDay(),
                'expires_at' => $expiresAt,
            ]);

            Schema::enableForeignKeyConstraints();

            return $row->refresh();
        });
    }

    private function packageBooking(StudentPackageEntitlement $entitlement, CarbonImmutable $startsAt): Booking
    {
        return app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->instructor->id,
            startsAt: $startsAt,
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
            packageEntitlementId: (string) $entitlement->id,
        ))->refresh();
    }

    // ── D. DST ────────────────────────────────────────────────────────────

    /**
     * The spring-forward Sunday. In Europe/London the clocks jump from
     * 00:59 GMT to 02:00 BST, so a local wall-clock reading of "01:30"
     * does not exist at all, and every local time after it maps to a UTC
     * instant one hour EARLIER than a naive reading suggests.
     */
    public function test_expiry_is_an_absolute_instant_across_a_spring_forward_transition(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-25 09:00:00', 'UTC'));

        // Package expires at 02:30 UTC on the transition day.
        $expiry = CarbonImmutable::parse('2026-03-29 02:30:00', 'UTC');
        $entitlement = $this->entitlement($expiry);

        // Local 03:00 BST == 02:00 UTC; a 60-minute lesson finishes at
        // 03:00 UTC, which is AFTER the 02:30 UTC expiry — even though a
        // naive local-time reading ("03:00 is before 03:30 local") would
        // wrongly say it fits.
        $localStart = CarbonImmutable::parse('2026-03-29 03:00:00', self::DST_ZONE);
        $this->assertSame('2026-03-29 02:00:00', $localStart->utc()->format('Y-m-d H:i:s'));

        $booking = $this->packageBooking($entitlement, CarbonImmutable::parse('2026-03-26 10:00:00', 'UTC'));

        $this->expectException(BookingException::class);

        app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $localStart,
            actor: BookingActor::Student,
            durationMinutes: 60,
        ));
    }

    public function test_a_lesson_genuinely_inside_validity_survives_the_same_transition(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-25 09:00:00', 'UTC'));

        $expiry = CarbonImmutable::parse('2026-03-29 02:30:00', 'UTC');
        $entitlement = $this->entitlement($expiry);

        // Local 01:00 GMT (before the jump) == 01:00 UTC; finishes 02:00
        // UTC, inside the 02:30 UTC expiry.
        $localStart = CarbonImmutable::parse('2026-03-29 00:30:00', self::DST_ZONE);
        $this->assertSame('2026-03-29 00:30:00', $localStart->utc()->format('Y-m-d H:i:s'));

        $booking = $this->packageBooking($entitlement, CarbonImmutable::parse('2026-03-26 10:00:00', 'UTC'));

        $moved = app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $localStart,
            actor: BookingActor::Student,
            durationMinutes: 60,
        ));

        // Persisted as the absolute instant, not the local reading.
        $this->assertSame('2026-03-29 00:30:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_expiry_boundary_is_inclusive_on_both_booking_and_reschedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-25 09:00:00', 'UTC'));

        $expiry = CarbonImmutable::parse('2026-03-29 02:00:00', 'UTC');
        $entitlement = $this->entitlement($expiry);

        $booking = $this->packageBooking($entitlement, CarbonImmutable::parse('2026-03-26 10:00:00', 'UTC'));

        // Finishes at EXACTLY expires_at — allowed, matching the same
        // `<=` rule the booking-time slot filter applies.
        $moved = app(BookingService::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: CarbonImmutable::parse('2026-03-29 01:00:00', 'UTC'),
            actor: BookingActor::Student,
            durationMinutes: 60,
        ));

        $this->assertSame('2026-03-29 01:00:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));

        // One minute later finishes one minute past expiry — refused.
        $this->expectException(BookingException::class);

        app(BookingService::class)->reschedule($moved->refresh(), new RescheduleBookingData(
            startsAt: CarbonImmutable::parse('2026-03-29 01:01:00', 'UTC'),
            actor: BookingActor::Student,
            durationMinutes: 60,
        ));
    }

    // ── B. Messaging relationship evidence ────────────────────────────────

    private function messaging(): MessagingEligibilityService
    {
        return app(MessagingEligibilityService::class);
    }

    public function test_a_package_funded_booking_is_the_same_relationship_evidence_as_a_paid_one(): void
    {
        Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::PackageFunded,
        ]);

        $this->assertNotNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }

    public function test_a_paid_booking_relationship_is_unchanged(): void
    {
        Booking::factory()->confirmed()->paid()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->assertNotNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }

    public function test_owning_a_paid_package_grants_no_messaging_without_a_booking(): void
    {
        // A settled purchase and an active entitlement, but no lesson
        // ever scheduled. Package OWNERSHIP is not a relationship.
        $entitlement = $this->entitlement(CarbonImmutable::now('UTC')->addYear());

        Schema::disableForeignKeyConstraints();
        StudentPackagePurchase::query()->create([
            'proposal_id' => (string) $entitlement->proposal_id,
            'student_id' => $this->student->id,
            'reference' => 'PKG-'.strtoupper(Str::random(12)),
            'amount_minor' => 20000,
            'currency_code' => 'GBP',
            'status' => PackagePurchaseStatus::Paid,
            'accepted_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);
        Schema::enableForeignKeyConstraints();

        $this->assertNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }

    public function test_a_pending_payment_booking_grants_no_messaging(): void
    {
        Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        $this->assertNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }

    public function test_another_instructors_package_booking_grants_nothing(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $otherInstructor->id,
            'payment_status' => BookingPaymentStatus::PackageFunded,
        ]);

        // The relationship is with the instructor the package was bought
        // from, and with nobody else.
        $this->assertNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }

    public function test_an_unconfirmed_package_booking_grants_nothing(): void
    {
        Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::PackageFunded,
        ]);

        $this->assertNull($this->messaging()->findEligibleContext($this->student, $this->instructor));
    }
}
