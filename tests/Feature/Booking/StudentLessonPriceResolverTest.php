<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingPriceCalculator;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin-managed student pricing matrix (`student_lesson_prices`)
 * is the *only* source of a paid
 * lesson's student-facing price. `booking_types.price`/`currency` no
 * longer exist (see BookingPriceCalculator) — a paid booking with no
 * matching active `StudentLessonPrice` row is always rejected.
 */
class StudentLessonPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private BookingType $paidType;

    private Subject $subject;

    private AcademicLevel $level;

    private Country $country;

    private Currency $currency;

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

        $this->paidType = BookingType::factory()->create([
            'key' => 'paid_one_to_one',
            'is_paid' => true,
            'duration_minutes' => 60,
        ]);

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);
        $this->level = AcademicLevel::create(['name' => 'Middle School', 'slug' => 'middle-school', 'min_grade' => 6, 'max_grade' => 8]);
        $this->currency = Currency::factory()->create(['code' => 'INR', 'minor_units' => 2]);
        $this->country = Country::factory()->create(['iso2' => 'IN', 'default_currency_id' => $this->currency->id]);
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $this->country->id]);

        return $student;
    }

    private function bookingData(User $student, array $metaOverrides = [], ?User $teacher = null): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            instructorId: ($teacher ?? $this->teacher)->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7, ...$metaOverrides],
        );
    }

    /** A second bookable instructor, distinct from $this->teacher, for instructor-override tests. */
    private function otherTeacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        return $teacher;
    }

    // ── 3. exact match ───────────────────────────────────────────────

    public function test_paid_booking_resolves_exact_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // 750.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('750.00', $booking->price);
        $this->assertSame('INR', $booking->currency);
    }

    // ── 4. academic_level null ("all levels") fallback ────────────────

    public function test_academic_level_null_fallback_works(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => null,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 60000, // 600.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('600.00', $booking->price);
    }

    public function test_exact_level_match_wins_over_all_levels_row(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => null,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 60000,
        ]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 90000,
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('900.00', $booking->price);
    }

    // ── Instructor-specific price override ──────────────────────────────

    public function test_instructor_specific_exact_level_price_overrides_base_exact_level_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base: 750.00
        ]);
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000, // instructor override: 1200.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('1200.00', $booking->price);
    }

    public function test_instructor_specific_all_level_price_overrides_base_exact_level_price_for_that_instructor_only(): void
    {
        // Base, exact level (would normally win over an "all levels" row).
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base exact-level: 750.00
        ]);
        // Instructor override, all levels — still outranks the base
        // exact-level row because instructor-specific wins outright.
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => null,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 110000, // instructor override, all levels: 1100.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('1100.00', $booking->price);
    }

    public function test_other_instructor_still_uses_base_price_when_override_exists_for_a_different_instructor(): void
    {
        $otherTeacher = $this->otherTeacher();

        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base: 750.00
        ]);
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000, // override only for $this->teacher: 1200.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student, [], $otherTeacher));

        // $otherTeacher has no override — falls through to the base price.
        $this->assertSame('750.00', $booking->price);
    }

    public function test_inactive_instructor_override_is_ignored_falls_back_to_base_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base: 750.00
        ]);
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->inactive()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000,
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('750.00', $booking->price);
    }

    public function test_not_yet_effective_instructor_override_is_ignored_falls_back_to_base_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base: 750.00
        ]);
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000,
            'effective_from' => now()->addWeek(), // starts next week — not yet effective
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('750.00', $booking->price);
    }

    public function test_expired_instructor_override_is_ignored_falls_back_to_base_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000, // base: 750.00
        ]);
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000,
            'effective_from' => now()->subMonth(),
            'effective_until' => now()->subWeek(), // expired last week
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $this->assertSame('750.00', $booking->price);
    }

    public function test_booking_snapshot_uses_instructor_specific_price_when_available(): void
    {
        StudentLessonPrice::factory()->forInstructor($this->teacher->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 135000, // 1350.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $fresh = Booking::query()->findOrFail($booking->id);
        $this->assertSame('1350.00', $fresh->price);
        $this->assertSame('INR', $fresh->currency);
    }

    // ── 5. missing price blocks paid booking ──────────────────────────

    public function test_missing_price_blocks_paid_booking(): void
    {
        // No matrix row, and legacy price/currency are null (setUp) — no
        // price resolvable anywhere.
        $student = $this->student();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('price is not configured');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    // ── 6. inactive price blocks (falls through, does not match) ──────

    public function test_inactive_price_is_never_matched(): void
    {
        StudentLessonPrice::factory()->inactive()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);

        $student = $this->student();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('price is not configured');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    // ── 7. legacy null price no longer becomes free ────────────────────

    public function test_paid_type_with_null_legacy_price_and_no_matrix_match_is_rejected_not_free(): void
    {
        $student = $this->student();

        try {
            app(BookingServiceInterface::class)->request($this->bookingData($student));
            $this->fail('Expected a BookingException — a paid type must never silently become free.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('price is not configured', $e->getMessage());
        }

        $this->assertDatabaseMissing('bookings', ['instructor_id' => $this->teacher->id]);
    }

    // ── Legacy booking_types.price/currency fallback is gone ────────────

    public function test_booking_type_no_longer_has_price_or_currency_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('booking_types', 'price'));
        $this->assertFalse(Schema::hasColumn('booking_types', 'currency'));
    }

    public function test_paid_booking_with_no_matrix_row_is_rejected_even_with_full_subject_grade_context(): void
    {
        // A matching subject/grade/country exists, but no StudentLessonPrice
        // row was ever created — there is no legacy column left to fall
        // back to, so this must be rejected exactly like a total miss.
        $student = $this->student();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('price is not configured');

        app(BookingServiceInterface::class)->request($this->bookingData($student));
    }

    public function test_paid_booking_with_no_subject_grade_context_is_rejected_not_silently_allowed(): void
    {
        // A booking with no subject/grade context has no meta to key
        // pricing off of — there is no legacy booking_types.price to
        // fall back to for it either.
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $student = $this->student();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('price is not configured');

        app(BookingServiceInterface::class)->request($this->bookingData($student, ['subject' => null, 'grade' => null]));
    }

    // ── 8. demo/free booking remains free ──────────────────────────────

    public function test_demo_free_booking_remains_free(): void
    {
        $freeType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        $price = app(BookingPriceCalculator::class)->calculate($freeType);

        $this->assertTrue($price->isFreeBooking);
        $this->assertFalse($price->requiresPayment);
        $this->assertSame(0.0, $price->payableAmount);
    }

    // ── 9. amount stored as integer minor units ────────────────────────

    public function test_amount_is_stored_as_integer_minor_units_not_a_float(): void
    {
        $price = StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->assertIsInt($price->amount_minor);
        $this->assertSame(49900, $price->fresh()->amount_minor);
        $this->assertSame(499.0, $price->fresh()->amountDecimal());
    }

    public function test_zero_or_negative_amount_minor_is_rejected_by_db_constraint(): void
    {
        $this->expectException(QueryException::class);

        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 0,
        ]);
    }

    // ── 10. booking/payment snapshot uses the resolved amount/currency ─

    public function test_booking_snapshot_uses_resolved_matrix_amount_and_currency(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 82500, // 825.00
        ]);

        $student = $this->student();
        $booking = app(BookingServiceInterface::class)->request($this->bookingData($student));

        $fresh = Booking::query()->findOrFail($booking->id);
        $this->assertSame('825.00', $fresh->price);
        $this->assertSame('INR', $fresh->currency);
        $this->assertSame('pending', $fresh->payment_status->value);
    }

    // ── 15-18. boundary guarantees ──────────────────────────────────────

    public function test_no_instructor_payout_wallet_debit_meeting_or_duplicate_tables_were_introduced(): void
    {
        // instructor_earnings is a separate liability ledger, not a
        // payout executor and not part of the pricing matrix.
        // `payments` (Phase 4B.1's generic Payable table) is excluded:
        // it exists on purpose and has nothing to do with the pricing
        // matrix, which is what this guard protects.
        foreach (['instructor_payouts', 'wallet_debits', 'meetings'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }

        // Exactly one pricing table, one payment table, one wallet ledger table.
        $this->assertTrue(Schema::hasTable('student_lesson_prices'));
        $this->assertTrue(Schema::hasTable('booking_payments'));
        $this->assertFalse(Schema::hasTable('booking_prices'));
        $this->assertFalse(Schema::hasTable('lesson_prices'));
        $this->assertFalse(Schema::hasTable('pricing_matrix'));
    }
}
