<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Services\BookingPriceCalculator;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use App\Settings\GeneralSettings;
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

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function slot(int $daysAhead = 3, int $hour = 9): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime($hour, 0);
    }

    // ── Pricing calculation ──────────────────────────────────────────────

    public function test_demo_booking_calculates_zero_payable_amount(): void
    {
        $demo = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'price' => null, 'currency' => null]);

        $price = app(BookingPriceCalculator::class)->calculate($demo);

        $this->assertSame(0.0, $price->baseAmount);
        $this->assertSame(0.0, $price->payableAmount);
        $this->assertFalse($price->requiresPayment);
        $this->assertTrue($price->isFreeBooking);
    }

    public function test_paid_booking_calculates_payable_amount(): void
    {
        $paid = BookingType::factory()->paid(49.99, 'USD')->create(['key' => 'paid_one_to_one']);

        $price = app(BookingPriceCalculator::class)->calculate($paid);

        $this->assertSame(49.99, $price->baseAmount);
        $this->assertSame(49.99, $price->payableAmount);
        $this->assertSame('USD', $price->currency);
        $this->assertTrue($price->requiresPayment);
        $this->assertFalse($price->isFreeBooking);
    }

    public function test_paid_type_with_admin_configured_zero_price_is_treated_as_free(): void
    {
        $free = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'price' => 0, 'currency' => 'USD']);

        $price = app(BookingPriceCalculator::class)->calculate($free);

        $this->assertSame(0.0, $price->payableAmount);
        $this->assertFalse($price->requiresPayment);
        $this->assertTrue($price->isFreeBooking);
    }

    public function test_currency_derives_from_student_country_when_type_currency_is_missing(): void
    {
        $currency = Currency::factory()->create(['code' => 'GBP']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);
        UserProfile::updateOrCreate(['user_id' => $this->student->id], ['country_id' => $country->id]);

        $type = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'currency' => null]);

        $price = app(BookingPriceCalculator::class)->calculate($type, $this->student->refresh());

        $this->assertSame('GBP', $price->currency);
    }

    public function test_currency_falls_back_to_general_settings_when_no_country_or_type_currency(): void
    {
        $type = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'currency' => null]);

        $price = app(BookingPriceCalculator::class)->calculate($type, $this->student);

        $this->assertSame(app(GeneralSettings::class)->default_currency, $price->currency);
    }

    // ── Demo vs paid booking boundary ────────────────────────────────────

    public function test_paid_booking_without_payment_is_not_marked_paid(): void
    {
        BookingType::factory()->paid(49.99, 'USD')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
        ));

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->payment_status);
    }

    public function test_paid_booking_creates_no_meeting_link(): void
    {
        BookingType::factory()->paid(49.99, 'USD')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
        ));

        $this->assertNull($booking->meeting_provider);
        $this->assertNull($booking->meeting_ref);
        $this->assertNull($booking->meeting_url);
    }

    public function test_paid_booking_creates_no_wallet_or_razorpay_records(): void
    {
        BookingType::factory()->paid(49.99, 'USD')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
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
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
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

        // Pricing remains solely on the existing booking_types/bookings columns.
        $this->assertTrue(Schema::hasColumn('booking_types', 'price'));
        $this->assertTrue(Schema::hasColumn('booking_types', 'currency'));
        $this->assertTrue(Schema::hasColumn('bookings', 'price'));
        $this->assertTrue(Schema::hasColumn('bookings', 'currency'));
    }

    public function test_inactive_booking_type_cannot_be_used(): void
    {
        BookingType::factory()->inactive()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        ));
    }

    public function test_duration_and_buffer_remain_compatible_with_availability_slots(): void
    {
        BookingType::factory()->paid(49.99, 'USD')->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 45,
            'buffer_minutes' => 15,
        ]);

        $slot = $this->slot();
        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $slot,
            durationMinutes: 45,
        ));

        $booking = Booking::query()->where('host_id', $this->teacher->id)->firstOrFail();
        $this->assertSame(45, (int) $slot->diffInMinutes($booking->ends_at));

        // Back-to-back start (ignoring the 15-minute buffer) must be rejected.
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $otherStudent->id,
            hostId: $this->teacher->id,
            startsAt: $slot->addMinutes(45),
            durationMinutes: 45,
        ));
    }

    // ── Admin cannot bypass payment status ───────────────────────────────

    public function test_admin_cannot_edit_payment_status_or_meeting_fields_on_unpaid_booking(): void
    {
        BookingType::factory()->paid(49.99, 'USD')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 60,
        ));
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);

        $admin = $this->permittedManager();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertFormFieldDisabled('meeting_provider')
            ->assertFormFieldDisabled('meeting_url')
            ->assertSchemaStateSet(['meeting_url' => null])
            ->call('save');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNull($booking->meeting_url);
    }

    public function test_admin_can_edit_meeting_fields_once_payment_is_settled(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            attendeeId: $this->student->id,
            hostId: $this->teacher->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
        ));
        $this->assertSame(BookingPaymentStatus::NotRequired, $booking->payment_status);

        $admin = $this->permittedManager();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertFormFieldEnabled('meeting_url');
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
