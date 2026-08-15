<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Services\LessonLifecycleService;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Enums\PackagePurchaseStatus;
use App\Reviews\Enums\ReviewableLessonType;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4E.4A (gap B) — a delivered package-funded lesson earns the same
 * review as any other delivered paid lesson.
 *
 * Phase 4E.3 changed the predicate from `=== Paid` to `isSettled()`, but
 * nothing ever drove a real package-funded lesson through it. That is
 * the gap this closes, and it is closed the hard way: the booking comes
 * from BookingService, the lesson from LessonLifecycleService, and the
 * eligibility from the real outcome-finalization path. Nothing under
 * test is factory-built.
 *
 * The negative half matters just as much. A package is a COMMERCIAL
 * entitlement; a review belongs to a DELIVERED lesson. Owning a package —
 * even a fully paid one — must never by itself grant the ability to
 * review an instructor.
 */
class PackageReviewEligibilityTest extends TestCase
{
    use CreatesStudentLessonPrices;
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

        $this->enableReviews();
        $this->seed(ReviewPermissionSeeder::class);
    }

    private function enableReviews(): void
    {
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->paid_lesson_reviews_enabled = true;
        $settings->demo_review_policy = 'private_only';
        $settings->review_window_days = 14;
        $settings->save();
    }

    private function entitlement(): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $this->student->id,
                'instructor_id' => $this->instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $this->subject->id,
                'paid_quantity' => 3,
                'bonus_quantity' => 0,
                'total_quantity' => 3,
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

    /** A real package-funded booking, from the real booking service. */
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

    /** Real lesson lifecycle + real outcome finalization — never a factory. */
    private function deliver(Booking $booking): Lesson
    {
        $lesson = app(LessonLifecycleService::class)->createFromBooking($booking);

        $this->assertNotNull($lesson, 'The package-funded booking must produce a lesson.');

        $this->travelTo($booking->ends_at->copy()->addMinutes(5));
        app(LessonOutcomeServiceInterface::class)->finalize($lesson->refresh(), LessonOutcome::Completed);

        return $lesson->refresh();
    }

    private function eligibilityFor(Lesson $lesson): ?LessonReviewEligibility
    {
        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->first();
    }

    // ── Case A — the claim ────────────────────────────────────────────────

    public function test_a_delivered_package_funded_lesson_is_review_eligible(): void
    {
        $booking = $this->packageBooking($this->entitlement());

        $this->assertSame(BookingPaymentStatus::PackageFunded, $booking->payment_status);

        $eligibility = $this->eligibilityFor($this->deliver($booking));

        $this->assertNotNull($eligibility, 'A delivered package-funded lesson must earn a review eligibility.');
        $this->assertSame($this->student->id, $eligibility->student_id);
    }

    public function test_it_is_treated_as_a_paid_lesson_review_not_a_demo(): void
    {
        $eligibility = $this->eligibilityFor($this->deliver($this->packageBooking($this->entitlement())));

        // Prepaid, not free: the public paid-lesson review, never the
        // private demo-feedback path.
        $this->assertSame(ReviewableLessonType::Paid, $eligibility->lesson_type);
    }

    // ── Case B/C/D — the other funding types are unchanged ────────────────

    public function test_an_ordinary_paid_lesson_remains_eligible(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(12, 0),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(13, 0),
        ]);

        $this->assertNotNull($this->eligibilityFor($this->deliver($booking)));
    }

    public function test_a_free_demo_follows_its_own_policy_unchanged(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(14, 0),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(15, 0),
        ]);

        $eligibility = $this->eligibilityFor($this->deliver($booking));

        // demo_review_policy = private_only — a demo is still a demo.
        $this->assertNotNull($eligibility);
        $this->assertSame(ReviewableLessonType::Demo, $eligibility->lesson_type);
    }

    public function test_an_unsettled_paid_booking_earns_no_eligibility(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::Pending,
            'price' => '499.00',
            'currency' => 'INR',
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(16, 0),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(3)->setTime(17, 0),
        ]);

        // Never becomes a lesson at all, so there is nothing to review —
        // the delivery guard and the review guard agree.
        $this->assertNull(app(LessonLifecycleService::class)->createFromBooking($booking));
    }

    // ── Case E — owning a package is not a delivered lesson ───────────────

    public function test_a_paid_package_with_no_delivered_lesson_grants_no_review(): void
    {
        $entitlement = $this->entitlement();

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

        // Package = commercial entitlement. Review = delivered lesson.
        // Paying for lessons buys none of the standing to review them.
        $this->assertSame(0, LessonReviewEligibility::query()->count());
    }

    public function test_a_booked_but_undelivered_package_lesson_grants_no_review(): void
    {
        $booking = $this->packageBooking($this->entitlement());
        $lesson = app(LessonLifecycleService::class)->createFromBooking($booking);

        // Scheduled, not delivered — eligibility follows completion.
        $this->assertNull($this->eligibilityFor($lesson));
    }

    // ── Case F/G — ownership boundaries ───────────────────────────────────

    public function test_the_eligibility_belongs_to_the_lesson_student_only(): void
    {
        $other = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $eligibility = $this->eligibilityFor($this->deliver($this->packageBooking($this->entitlement())));

        $this->assertNotSame($other->id, $eligibility->student_id);
        $this->assertSame(
            0,
            LessonReviewEligibility::query()->where('student_id', $other->id)->count(),
            'Another student must never inherit a package lesson’s review eligibility.',
        );
    }

    public function test_a_package_with_one_instructor_grants_no_review_of_another(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $otherInstructor->id], ['instructor_status' => 'approved']);

        $eligibility = $this->eligibilityFor($this->deliver($this->packageBooking($this->entitlement())));

        // The eligibility is anchored to the lesson actually delivered,
        // so it can only ever concern the instructor who taught it.
        $this->assertSame($this->instructor->id, $eligibility->lesson->instructor_id);
        $this->assertSame(
            0,
            LessonReviewEligibility::query()
                ->whereHas('lesson', fn ($q) => $q->where('instructor_id', $otherInstructor->id))
                ->count(),
        );
    }

    // ── Case H — one review per delivered lesson ──────────────────────────

    public function test_completion_replay_does_not_create_a_second_eligibility(): void
    {
        $booking = $this->packageBooking($this->entitlement());
        $lesson = $this->deliver($booking);

        app(LessonOutcomeServiceInterface::class)->finalize($lesson->refresh(), LessonOutcome::Completed);

        $this->assertSame(1, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_the_booking_kept_its_package_funding_throughout(): void
    {
        $booking = $this->packageBooking($this->entitlement());
        $this->deliver($booking);

        $booking->refresh();

        // The lesson was reviewable BECAUSE it was financially covered,
        // not because it was quietly reclassified as an ordinary payment.
        $this->assertSame(BookingPaymentStatus::PackageFunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertNotNull($booking->price);
    }
}
