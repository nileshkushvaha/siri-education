<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Services\BookingPriceCalculator;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\AcademicCategory;
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
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use App\Settings\GeneralSettings;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingPricingCheckoutReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Subject $subject;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)
                ->between('09:00:00', '11:00:00')
                ->create();
        }

        $this->currency = Currency::factory()->create(['code' => 'USD']);
        $this->country = Country::factory()->create(['default_currency_id' => $this->currency->id]);
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->student->id], ['country_id' => $this->country->id]);
    }

    private function slot(int $daysAhead = 3, int $hour = 9): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime($hour, 0);
    }

    /** meta matching this file's shared subject fixture — pass to CreateBookingData for a resolvable paid price. */
    private function subjectMeta(int $grade = 7): array
    {
        return ['subject' => 'maths', 'grade' => $grade];
    }

    private function seedLessonPrice(BookingType $type, float $amount = 49.99, ?int $durationMinutes = null): StudentLessonPrice
    {
        return StudentLessonPrice::factory()->create([
            'booking_type_id' => $type->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => null,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => $this->currency->code,
            'duration_minutes' => $durationMinutes ?? $type->duration_minutes,
            'amount_minor' => (int) round($amount * 100),
        ]);
    }

    /** A paid, priced booking type ready for CreateBookingData(..., meta: $this->subjectMeta()). */
    private function paidTypeWithPrice(float $amount = 49.99, int $duration = 60): BookingType
    {
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => $duration]);
        $this->seedLessonPrice($type, $amount, $duration);

        return $type;
    }

    // ── Pricing calculation ──────────────────────────────────────────────

    public function test_demo_booking_calculates_zero_payable_amount(): void
    {
        $demo = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);

        $price = app(BookingPriceCalculator::class)->calculate($demo);

        $this->assertSame(0.0, $price->baseAmount);
        $this->assertSame(0.0, $price->payableAmount);
        $this->assertFalse($price->requiresPayment);
        $this->assertTrue($price->isFreeBooking);
    }

    public function test_paid_booking_calculates_payable_amount(): void
    {
        $paid = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $this->seedLessonPrice($paid, 49.99);

        $price = app(BookingPriceCalculator::class)->calculate($paid, $this->student, 'maths', 7);

        $this->assertSame(49.99, $price->baseAmount);
        $this->assertSame(49.99, $price->payableAmount);
        $this->assertSame('USD', $price->currency);
        $this->assertTrue($price->requiresPayment);
        $this->assertFalse($price->isFreeBooking);
    }

    public function test_paid_type_with_no_matrix_price_is_rejected_not_treated_as_free(): void
    {
        // Phase 10.2C-Fix / 10.2D-Cleanup: a paid type can no longer
        // silently resolve to a free booking because pricing was left
        // unconfigured — it must be rejected so the gap is caught and
        // fixed by an admin instead of reaching students as an
        // unintended free lesson. There is no `booking_types.price`
        // column left to misconfigure either — the only way to price a
        // paid type is a `StudentLessonPrice` row, and none exists here.
        $unconfigured = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('price is not configured');

        app(BookingPriceCalculator::class)->calculate($unconfigured, $this->student, 'maths', 7);
    }

    public function test_paid_type_is_rejected_without_full_matrix_context(): void
    {
        $type = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);
        $this->seedLessonPrice($type, 25.00);

        // No subject/grade context at all (e.g. a non-subject booking type).
        try {
            app(BookingPriceCalculator::class)->calculate($type, $this->student);
            $this->fail('Expected BookingException when no subject/grade context is given.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('price is not configured', $e->getMessage());
        }

        // No student (so no billing country) — matrix can't be attempted either.
        $this->expectException(BookingException::class);

        app(BookingPriceCalculator::class)->calculate($type, null, 'maths', 7);
    }

    public function test_currency_derives_from_student_country_for_a_free_booking(): void
    {
        $currency = Currency::factory()->create(['code' => 'GBP']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);
        UserProfile::updateOrCreate(['user_id' => $this->student->id], ['country_id' => $country->id]);

        $type = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);

        $price = app(BookingPriceCalculator::class)->calculate($type, $this->student->refresh());

        $this->assertSame('GBP', $price->currency);
    }

    public function test_currency_falls_back_to_general_settings_when_no_country_for_a_free_booking(): void
    {
        $studentWithNoCountry = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $type = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);

        $price = app(BookingPriceCalculator::class)->calculate($type, $studentWithNoCountry);

        $this->assertSame(app(GeneralSettings::class)->default_currency, $price->currency);
    }

    // ── Demo vs paid booking boundary ────────────────────────────────────

    public function test_paid_booking_without_payment_is_not_marked_paid(): void
    {
        $this->paidTypeWithPrice();

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
            meta: $this->subjectMeta(),
        ));

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->payment_status);
    }

    public function test_paid_booking_creates_no_meeting_link(): void
    {
        $this->paidTypeWithPrice();

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
            meta: $this->subjectMeta(),
        ));

        $this->assertNull($booking->meeting_provider);
        $this->assertNull($booking->meeting_ref);
        $this->assertNull($booking->meeting_url);
    }

    public function test_paid_booking_creates_no_wallet_or_razorpay_records(): void
    {
        $this->paidTypeWithPrice();

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
            meta: $this->subjectMeta(),
        ));

        $this->assertSame('fake', app(BookingSettings::class)->payment_provider);

        // wallets/wallet_ledger_entries are the approved Phase 9 foundation —
        // booking creation must still never write to them.
        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletLedgerEntry::count());

        foreach (['razorpay_orders', 'payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }
    }

    public function test_free_demo_booking_auto_confirms_and_requires_no_payment(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        ));

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(BookingPaymentStatus::NotRequired, $booking->payment_status);
        $this->assertNull($booking->price);
    }

    public function test_no_duplicate_payment_wallet_or_pricing_tables_exist(): void
    {
        // wallets/wallet_ledger_entries are the approved Phase 9 foundation —
        // everything else pricing/payment-adjacent remains absent.
        foreach ([
            'wallet_transactions', 'wallet_settings',
            'payments', 'payment_transactions', 'razorpay_orders',
            'pricing', 'price_matrices', 'booking_type_prices', 'booking_type_country_prices',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }

        $this->assertTrue(Schema::hasTable('wallets'));
        $this->assertTrue(Schema::hasTable('wallet_ledger_entries'));

        // Phase 10.2D-Cleanup: booking_types no longer owns a price at
        // all — student_lesson_prices is the only pricing table.
        // bookings.price/currency remain — the point-in-time snapshot
        // taken at booking-creation time, not a duplicate pricing source.
        $this->assertFalse(Schema::hasColumn('booking_types', 'price'));
        $this->assertFalse(Schema::hasColumn('booking_types', 'currency'));
        $this->assertTrue(Schema::hasTable('student_lesson_prices'));
        $this->assertTrue(Schema::hasColumn('bookings', 'price'));
        $this->assertTrue(Schema::hasColumn('bookings', 'currency'));
    }

    public function test_inactive_booking_type_cannot_be_used(): void
    {
        BookingType::factory()->inactive()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        ));
    }

    public function test_duration_and_buffer_remain_compatible_with_availability_slots(): void
    {
        $type = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 45,
            'buffer_minutes' => 15,
        ]);
        $this->seedLessonPrice($type, 49.99, 45);

        $slot = $this->slot();
        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $slot,
            durationMinutes: 45,
            meta: $this->subjectMeta(),
        ));

        $booking = Booking::query()->where('instructor_id', $this->teacher->id)->firstOrFail();
        $this->assertSame(45, (int) $slot->diffInMinutes($booking->ends_at));

        // Back-to-back start (ignoring the 15-minute buffer) must be rejected.
        $otherStudent = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $otherStudent->id,
            instructorId: $this->teacher->id,
            startsAt: $slot->addMinutes(45),
            durationMinutes: 45,
            meta: $this->subjectMeta(),
        ));
    }

    // ── Admin cannot bypass payment status ───────────────────────────────

    /**
     * Phase 11 note: the Meeting section on EditBooking is now a
     * read-only summary of the booking_meetings relationship — mutating
     * it goes exclusively through BookingsTable's "Create/Update
     * Meeting" action (BookingMeetingService), not editable form
     * fields. Payment-status gating is therefore enforced by
     * BookingMeetingService::isEligible() (payment_status must be Paid
     * for a paid type), not by a disabled form field.
     */
    public function test_admin_cannot_create_meeting_fields_on_unpaid_booking(): void
    {
        $this->paidTypeWithPrice();

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
            meta: $this->subjectMeta(),
        ));
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);

        $admin = $this->permittedManager();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertFormFieldDisabled('meeting_status_label')
            ->assertFormFieldDisabled('meeting_join_url');

        $this->assertFalse(app(BookingMeetingServiceInterface::class)->isEligible($booking));
        $this->assertNull($booking->refresh()->meeting);
    }

    public function test_admin_can_see_meeting_summary_once_payment_is_settled_and_meeting_created(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $this->student->id,
            instructorId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        ));
        $this->assertSame(BookingPaymentStatus::NotRequired, $booking->payment_status);

        app(MeetingSettings::class)->fill([
            'meetings_enabled' => true,
            'manual_provider_enabled' => true,
        ])->save();
        app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/settled'),
        );

        $admin = $this->permittedManager();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertFormFieldDisabled('meeting_status_label')
            ->assertSuccessful();

        $this->assertSame('created', $booking->refresh()->meeting?->status->value);
    }

    private function permittedManager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        foreach (['ViewAny:Booking', 'View:Booking', 'Update:Booking'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $manager->givePermissionTo(['ViewAny:Booking', 'View:Booking', 'Update:Booking']);

        return $manager;
    }
}
