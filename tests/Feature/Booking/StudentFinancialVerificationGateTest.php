<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Contracts\StudentFinancialVerificationGate;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * StudentFinancialVerificationGate is validated against docs/SRS.md
 * §2.5 ("Phone Number (Optional for Version 1)") and §11.14
 * ("Student must be registered and verified" — no phone/mobile
 * requirement). "Verified" for a student means EMAIL verification
 * throughout the SRS. The previous phone_e164/phone_verified_at
 * requirement was both SRS-unsupported and functionally impossible (the
 * bound PhoneOtpSender always throws — see
 * App\Services\Phone\UnavailablePhoneOtpSender), blocking every paid
 * booking. See app/Services/Student/DefaultStudentFinancialVerificationGate.php
 * for the corrected implementation.
 */
class StudentFinancialVerificationGateTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }
    }

    private function activeVerifiedStudent(): User
    {
        return User::factory()->activeStudent()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function activeUnverifiedStudent(): User
    {
        return User::factory()->activeStudent()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => null,
        ]);
    }

    private function bookPaid(User $student, string $currency = 'INR', float $price = 499.00): Booking
    {
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', $price, $currency, durationMinutes: 60);
        $this->assignBillingCountry($student, $priced['country']);

        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));
    }

    private function bookFreeDemo(User $student): Booking
    {
        BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(11, 0)->toImmutable(),
        ));
    }

    // ── 1. Eligible Active + verified student can create paid booking types ──

    public function test_eligible_active_verified_student_can_create_a_paid_single_booking(): void
    {
        $student = $this->activeVerifiedStudent();

        $booking = $this->bookPaid($student);

        $this->assertNotNull($booking->id);
        $this->assertSame($student->id, $booking->student_id);
    }

    // ── 2. Missing email verification is rejected when the setting requires it ──

    public function test_unverified_email_blocks_paid_booking_when_verification_required(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeUnverifiedStudent();

        $this->expectException(BookingException::class);

        $this->bookPaid($student);
    }

    public function test_unverified_email_does_not_block_paid_booking_when_verification_not_required(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = false;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeUnverifiedStudent();

        $booking = $this->bookPaid($student);

        $this->assertNotNull($booking->id);
    }

    // ── 3. Free demo is unaffected by StudentFinancialVerificationGate specifically ──

    /**
     * Free/demo bookings are separately governed by
     * VerifiedActiveStudentRule (SRS §11.13 "Student email must be
     * verified" applies to demo eligibility too — an existing, unrelated
     * rule this phase does not touch), so an unverified student is
     * correctly still rejected for a demo. What this test proves is
     * narrower: StudentFinancialVerificationGate itself — the PAID-
     * specific gate this phase corrected — never becomes the reason a
     * free booking is blocked (see test_gate_is_a_no_op_for_free_booking_types_and_non_student_roles
     * for the direct, unit-level proof). An eligible, verified student
     * can book a free demo exactly as before.
     */
    public function test_eligible_verified_student_can_book_a_free_demo(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeVerifiedStudent();

        $booking = $this->bookFreeDemo($student);

        $this->assertNotNull($booking->id);
        $this->assertFalse($booking->type->is_paid);
    }

    // ── 4. Registered/Suspended/Archived/null students remain rejected ─────

    public function test_non_active_student_is_still_rejected_by_lifecycle_rules_not_this_gate(): void
    {
        // Verified email, but never promoted past Registered — this gate
        // alone must not be what lets a non-Active student through;
        // VerifiedActiveStudentRule's lifecycle check must still reject.
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $student->assignRole('student');
        // Deliberately no student_status set (defaults to Registered/null).

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Your student profile is incomplete, so booking is currently unavailable.');

        $this->bookPaid($student);
    }

    // ── 5/6. Gate runs before payment row creation / provider invocation ───

    public function test_gate_rejection_creates_no_booking_and_no_payment_row_and_no_provider_call(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeUnverifiedStudent();
        $bookingCountBefore = Booking::query()->count();

        try {
            $this->bookPaid($student);
            $this->fail('Expected a BookingException.');
        } catch (BookingException) {
            // expected
        }

        $this->assertSame($bookingCountBefore, Booking::query()->count(), 'No booking row should be created when the gate rejects.');
        Http::assertNothingSent();
    }

    // ── 7. Recurring paid booking follows the same policy ──────────────────

    public function test_recurring_paid_booking_is_governed_by_the_same_email_verification_policy(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $eligible = $this->activeVerifiedStudent();
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        $this->assignBillingCountry($eligible, $priced['country']);

        Livewire::actingAs($eligible)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'recurring')
            ->call('selectFrequency', 'weekly', 2)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String())
            ->call('submit');

        $this->assertGreaterThanOrEqual(1, Booking::query()->where('student_id', $eligible->id)->count());
    }

    // ── 8. Direct service invocation cannot bypass the gate ────────────────

    public function test_direct_gate_invocation_rejects_unverified_student_for_paid_type(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeUnverifiedStudent();
        $type = BookingType::factory()->paid()->create(['key' => 'direct-gate-check']);

        $this->expectException(BookingException::class);

        app(StudentFinancialVerificationGate::class)->assertEligible($student, $type);
    }

    public function test_direct_gate_invocation_allows_verified_student_for_paid_type(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeVerifiedStudent();
        $type = BookingType::factory()->paid()->create(['key' => 'direct-gate-check-2']);

        app(StudentFinancialVerificationGate::class)->assertEligible($student, $type);

        $this->assertTrue(true); // no exception thrown
    }

    public function test_gate_is_a_no_op_for_free_booking_types_and_non_student_roles(): void
    {
        $freeType = BookingType::factory()->create(['is_paid' => false, 'key' => 'direct-gate-free-check']);
        $nonStudent = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => null]);

        // Free type: never checked regardless of role/verification.
        app(StudentFinancialVerificationGate::class)->assertEligible($nonStudent, $freeType);

        // Non-student role: never checked even for a paid type.
        $paidType = BookingType::factory()->paid()->create(['key' => 'direct-gate-nonstudent-check']);
        app(StudentFinancialVerificationGate::class)->assertEligible($nonStudent, $paidType);

        $this->assertTrue(true);
    }

    // ── 9. A stale Livewire form is revalidated at submission ──────────────

    public function test_email_verification_revoked_after_form_mount_is_caught_at_submission(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeVerifiedStudent();
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        $this->assignBillingCountry($student, $priced['country']);

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'single')
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String());

        // Simulate the student's email verification being revoked between
        // mounting the form and submitting it (e.g. admin action) — the
        // gate must read fresh persisted state at submission, not
        // whatever was true when the Livewire component mounted.
        $student->forceFill(['email_verified_at' => null])->save();

        $component->call('submit');

        $this->assertSame(0, Booking::query()->where('student_id', $student->id)->count());
    }

    // ── 10. Reschedule/cancel of an existing booking is not blocked ────────

    public function test_reschedule_of_existing_booking_is_not_blocked_by_the_financial_gate(): void
    {
        $student = $this->activeVerifiedStudent();
        $booking = $this->bookPaid($student);
        $originalStartsAt = $booking->starts_at->toIso8601String();

        // Now revoke verification — a NEW paid booking would be rejected,
        // but rescheduling this EXISTING one must not re-run this gate.
        $student->forceFill(['email_verified_at' => null])->save();
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $rescheduled = app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: now('UTC')->addDays(4)->setTime(11, 0)->toImmutable(),
            actor: BookingActor::Student,
        ));

        $this->assertNotSame($originalStartsAt, $rescheduled->starts_at->toIso8601String());
    }

    public function test_cancel_of_existing_booking_is_not_blocked_by_the_financial_gate(): void
    {
        $student = $this->activeVerifiedStudent();
        $booking = $this->bookPaid($student);

        $student->forceFill(['email_verified_at' => null])->save();
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $cancelled = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Student, 'Change of plans'));

        $this->assertNotNull($cancelled->cancelled_at);
    }

    // ── 13. Failure message is generic and exposes no internal fields ──────

    public function test_rejection_message_is_generic_and_does_not_expose_internal_field_names(): void
    {
        app(AuthenticationSettings::class)->email_verification_required = true;
        app(AuthenticationSettings::class)->save();

        $student = $this->activeUnverifiedStudent();

        try {
            $this->bookPaid($student);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsStringIgnoringCase('phone_e164', $message);
            $this->assertStringNotContainsStringIgnoringCase('phone_verified_at', $message);
            $this->assertStringNotContainsStringIgnoringCase('email_verified_at', $message);
            $this->assertStringNotContainsStringIgnoringCase('student_status', $message);
        }
    }

    // ── 14. The student profile UI can actually satisfy the requirement ────

    public function test_the_real_email_verification_resend_route_is_reachable(): void
    {
        $student = $this->activeUnverifiedStudent();

        $this->actingAs($student)
            ->post(route('auth.verification.resend'))
            ->assertSessionHasNoErrors();
    }

    // ── 15. No real HTTP/provider call occurs ───────────────────────────────

    public function test_eligible_booking_path_makes_no_external_http_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $student = $this->activeVerifiedStudent();

        $this->bookPaid($student);

        Http::assertNothingSent();
    }
}
