<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingSeriesStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingSeriesPrepaymentService;
use App\Booking\Services\BookingSeriesService;
use App\Jobs\Booking\GenerateBookingSeriesOccurrences;
use App\Listeners\Booking\SettleSeriesPrepaymentOnWalletRechargeSucceeded;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingSeries;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Events\WalletRechargeSucceeded;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Paying for a whole repeating schedule in one go.
 *
 * The behaviour these lock down is not "a payment succeeds" — the
 * single-booking wallet path already guarantees that. It is that
 * batching changes ONLY the number of times a student is asked for
 * money: each class keeps its own price, its own reservation and its
 * own payment record, a class that fails leaves the others paid and the
 * money still in the balance, and nothing is ever collected for a class
 * that does not exist yet.
 */
class SeriesPrepaymentTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private BookingType $paidType;

    private User $instructor;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;

        // A real priced type: generation runs the ordinary engine, and a
        // paid type with no configured price is a configuration error
        // there, not a free class.
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        $this->paidType = $priced['type'];
        $this->country = $priced['country'];
        $this->seedStudentLessonPrice($priced['type'], $priced['country'], $priced['currency'], 499.00, 'maths', 60);

        // Genuinely bookable, so the generation pass in
        // test_the_generation_job_confirms_newly_created_classes creates a
        // real class through the ordinary engine rather than a fixture.
        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->instructor->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
            'timezone' => 'UTC',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $this->instructor->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $this->instructor->id])
                ->forDay($day)
                ->between('00:00:00', '23:59:00')
                ->create();
        }

        $settings = app(BookingSettings::class);
        $settings->maximum_advance_booking_days = 3650;
        $settings->max_daily_bookings_per_teacher = null;
        $settings->save();
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->assignBillingCountry($student, $this->country);
        UserProfile::where('user_id', $student->id)->update([
            // The engine's own eligibility rules apply to a generated
            // class exactly as they do to a booked one.
            'phone_e164' => '+9199'.str_pad((string) $student->id, 8, '0', STR_PAD_LEFT),
            'phone_verified_at' => now(),
        ]);

        return $student->refresh();
    }

    /** A schedule with $classes reserved, unpaid classes. */
    private function series(User $student, int $classes = 3, float $price = 499.00, string $currency = 'INR'): BookingSeries
    {
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        $series = BookingSeries::query()->create([
            'booking_type_id' => $this->paidType->id,
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingSeriesStatus::Active,
            'frequency' => RecurrenceFrequency::Weekly,
            'repeat_interval' => 1,
            'weekdays' => [(int) $start->dayOfWeek],
            'start_date' => $start->toDateString(),
            'time_of_day' => $start->format('H:i:s'),
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'student_timezone' => 'UTC',
            'end_condition' => RecurrenceEndCondition::AfterCount,
            'occurrence_count' => $classes,
            'meta' => ['subject' => 'maths', 'grade' => 5],
        ]);

        for ($i = 0; $i < $classes; $i++) {
            $startsAt = $start->addWeeks($i);

            Booking::factory()->create([
                'student_id' => $student->id,
                'instructor_id' => $this->instructor->id,
                'booking_type_id' => $this->paidType->id,
                'booking_series_id' => $series->id,
                'series_occurrence_date' => $startsAt->toDateString(),
                'status' => BookingStatus::Pending,
                'payment_status' => BookingPaymentStatus::Pending,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes(60),
                'price' => $price,
                'currency' => $currency,
                'reserved_until' => CarbonImmutable::now('UTC')->addMinutes(30),
            ]);
        }

        return $series->refresh();
    }

    private function fundWallet(User $student, int $amountMinor, string $currency = 'INR'): Wallet
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($student, $currency, $student);
        app(WalletLedgerService::class)->credit($wallet, $amountMinor, WalletLedgerEntryType::PromotionalCredit, $student);

        return $wallet->fresh();
    }

    private function prepayments(): BookingSeriesPrepaymentService
    {
        return app(BookingSeriesPrepaymentService::class);
    }

    // ── The quote ──────────────────────────────────────────────────────────

    public function test_the_quote_totals_every_reserved_class(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);

        $quote = $this->prepayments()->quote($series, $student);

        $this->assertSame(3, $quote->count());
        $this->assertSame(149700, $quote->totalMinor);
        $this->assertSame('INR', $quote->currencyCode);
        $this->assertTrue($quote->isPayable());
    }

    public function test_the_shortfall_is_exactly_the_gap_never_more(): void
    {
        // The platform should not end up holding more of a student's
        // money than the thing they are buying costs.
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 50000);

        $quote = $this->prepayments()->quote($series, $student);

        $this->assertSame(50000, $quote->walletBalanceMinor);
        $this->assertSame(99700, $quote->shortfallMinor);
        $this->assertFalse($quote->coveredByWallet());
    }

    public function test_a_balance_that_already_covers_the_bill_needs_no_checkout(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 200000);

        $quote = $this->prepayments()->quote($series, $student);

        $this->assertSame(0, $quote->shortfallMinor);
        $this->assertTrue($quote->coveredByWallet());
    }

    public function test_already_paid_classes_are_not_quoted_again(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);

        $series->bookings()->orderBy('starts_at')->first()
            ->forceFill(['payment_status' => BookingPaymentStatus::Paid])->save();

        $quote = $this->prepayments()->quote($series, $student);

        $this->assertSame(2, $quote->count());
        $this->assertSame(99800, $quote->totalMinor);
    }

    public function test_cancelled_classes_are_not_quoted(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);

        $series->bookings()->orderBy('starts_at')->first()
            ->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->assertSame(2, $this->prepayments()->quote($series, $student)->count());
    }

    public function test_a_mixed_currency_batch_is_refused_rather_than_half_settled(): void
    {
        // Should never happen — one schedule is one instructor at one
        // price — but a half-settled batch is far worse than a refusal.
        $student = $this->student();
        $series = $this->series($student, 2);

        $series->bookings()->orderBy('starts_at')->first()
            ->forceFill(['currency' => 'USD'])->save();

        $quote = $this->prepayments()->quote($series->refresh(), $student);

        $this->assertFalse($quote->isPayable());
        $this->assertStringContainsString('different currencies', (string) $quote->blockedReason);
    }

    public function test_a_wallet_in_another_currency_blocks_the_batch(): void
    {
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->fundWallet($student, 100000, 'USD');

        $quote = $this->prepayments()->quote($series, $student);

        $this->assertFalse($quote->isPayable());
        $this->assertStringContainsString('different currency', (string) $quote->blockedReason);
    }

    public function test_another_students_schedule_cannot_be_quoted_or_paid(): void
    {
        $owner = $this->student();
        $series = $this->series($owner, 2);
        $stranger = $this->student();

        $this->expectException(BookingException::class);
        $this->prepayments()->quote($series, $stranger);
    }

    // ── Settling ───────────────────────────────────────────────────────────

    public function test_one_action_pays_every_class_and_each_keeps_its_own_payment_record(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 200000);

        $result = $this->prepayments()->settleFromWallet($series, $student);

        $this->assertTrue($result->allPaid());
        $this->assertSame(3, $result->paidCount());

        // Per-class semantics survive: three classes, three payment
        // records, three prices — only the number of checkouts changed.
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
        $this->assertSame(3, BookingPayment::query()
            ->whereIn('booking_id', $series->bookings()->pluck('id'))
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->count());

        // Exactly the bill was taken, not a penny more.
        $this->assertSame(200000 - 149700, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_classes_are_settled_earliest_first_so_a_short_balance_secures_the_soonest(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        // Enough for two of the three.
        $this->fundWallet($student, 100000);

        $result = $this->prepayments()->settleFromWallet($series, $student);

        $this->assertSame(2, $result->paidCount());
        $this->assertCount(1, $result->failures);

        $paid = $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->orderBy('starts_at')->get();
        $unpaid = $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->get();

        $this->assertCount(2, $paid);
        $this->assertCount(1, $unpaid);
        $this->assertTrue($paid->last()->starts_at->lessThan($unpaid->first()->starts_at));
    }

    public function test_a_failed_class_leaves_the_others_paid_and_the_money_in_the_balance(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 200000);

        // One class loses its slot while the student is at the gateway.
        $doomed = $series->bookings()->orderByDesc('starts_at')->first();
        $doomed->forceFill(['status' => BookingStatus::Cancelled])->save();

        $result = $this->prepayments()->settleFromWallet($series->refresh(), $student);

        $this->assertSame(2, $result->paidCount());
        // Only the two that could be paid were charged for.
        $this->assertSame(200000 - 99800, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_paying_twice_does_not_charge_twice(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 300000);

        $this->prepayments()->settleFromWallet($series, $student);
        $balanceAfterFirst = (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor');

        // A double click, a retried job, a refreshed tab.
        $second = $this->prepayments()->settleFromWallet($series->refresh(), $student);

        $this->assertSame(0, $second->paidCount(), 'nothing is left to pay for');
        $this->assertSame($balanceAfterFirst, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    // ── Finishing what the student started ─────────────────────────────────

    public function test_a_prepayment_top_up_settles_the_classes_when_it_lands(): void
    {
        // The student closed the tab at the gateway. The webhook still
        // has to finish the job they paid for.
        $student = $this->student();
        $series = $this->series($student, 3);
        $wallet = $this->fundWallet($student, 149700);

        $recharge = WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'amount_minor' => 149700,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Succeeded,
            'reference' => 'WRCH-TEST00000001',
            'metadata' => [
                'purpose' => BookingSeriesPrepaymentService::PURPOSE,
                'booking_series_id' => (string) $series->id,
                'booking_ids' => $series->bookings()->pluck('id')->all(),
            ],
        ]);

        app(SettleSeriesPrepaymentOnWalletRechargeSucceeded::class)
            ->handle(new WalletRechargeSucceeded($recharge));

        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
    }

    public function test_an_ordinary_top_up_is_never_spent_on_the_students_behalf(): void
    {
        // The line that keeps this from being automatic charging: money
        // added for no stated purpose stays where the student put it.
        $student = $this->student();
        $series = $this->series($student, 3);
        $wallet = $this->fundWallet($student, 300000);

        $recharge = WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'amount_minor' => 300000,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Succeeded,
            'reference' => 'WRCH-TEST00000002',
            'metadata' => null,
        ]);

        app(SettleSeriesPrepaymentOnWalletRechargeSucceeded::class)
            ->handle(new WalletRechargeSucceeded($recharge));

        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->count());
        $this->assertSame(300000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_a_redelivered_settlement_event_does_not_charge_twice(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $wallet = $this->fundWallet($student, 200000);

        $recharge = WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'amount_minor' => 149700,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Succeeded,
            'reference' => 'WRCH-TEST00000003',
            'metadata' => [
                'purpose' => BookingSeriesPrepaymentService::PURPOSE,
                'booking_series_id' => (string) $series->id,
                'booking_ids' => $series->bookings()->pluck('id')->all(),
            ],
        ]);

        $listener = app(SettleSeriesPrepaymentOnWalletRechargeSucceeded::class);
        $listener->handle(new WalletRechargeSucceeded($recharge));
        $balance = (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor');

        $listener->handle(new WalletRechargeSucceeded($recharge));

        $this->assertSame($balance, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
    }

    // ── Safety net: paid top-ups whose classes were never settled ──────────

    /** A credited schedule top-up the listener never got to. */
    private function strandedPrepaymentRecharge(User $student, BookingSeries $series, int $amountMinor, string $reference): WalletRecharge
    {
        $wallet = $this->fundWallet($student, $amountMinor);

        return WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'amount_minor' => $amountMinor,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Succeeded,
            'succeeded_at' => CarbonImmutable::now('UTC')->subMinutes(20),
            'reference' => $reference,
            'metadata' => [
                'purpose' => BookingSeriesPrepaymentService::PURPOSE,
                'booking_series_id' => (string) $series->id,
                'booking_ids' => $series->bookings()->pluck('id')->all(),
            ],
        ]);
    }

    public function test_the_sweep_settles_classes_from_a_paid_top_up_the_listener_missed(): void
    {
        // Queue down, job lost, tries exhausted: the money is in the
        // wallet and the classes are still unpaid. The sweep finishes it.
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->strandedPrepaymentRecharge($student, $series, 149700, 'WRCH-SWEEP0000001');

        $this->artisan('booking:settle-series-prepayments')
            ->expectsOutputToContain('Settled 3 schedule class(es)')
            ->assertSuccessful();

        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_the_sweep_leaves_ordinary_top_ups_and_unpaid_recharges_alone(): void
    {
        $student = $this->student();
        $series = $this->series($student, 3);
        $wallet = $this->fundWallet($student, 300000);

        // Money added for no stated purpose is never spent on classes.
        WalletRecharge::query()->create([
            'wallet_id' => $wallet->id, 'user_id' => $student->id, 'amount_minor' => 300000, 'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Succeeded, 'succeeded_at' => now(), 'reference' => 'WRCH-SWEEP0000002', 'metadata' => null,
        ]);
        // A schedule top-up that has not been paid yet is not ours either.
        WalletRecharge::query()->create([
            'wallet_id' => $wallet->id, 'user_id' => $student->id, 'amount_minor' => 149700, 'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Requested, 'reference' => 'WRCH-SWEEP0000003',
            'metadata' => ['purpose' => BookingSeriesPrepaymentService::PURPOSE, 'booking_series_id' => (string) $series->id, 'booking_ids' => []],
        ]);

        $this->assertSame(0, $this->prepayments()->settleOutstandingRecharges());
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->count());
        $this->assertSame(300000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_a_second_sweep_pass_finds_nothing_left_to_pay(): void
    {
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->strandedPrepaymentRecharge($student, $series, 99800, 'WRCH-SWEEP0000004');

        $this->assertSame(2, $this->prepayments()->settleOutstandingRecharges());
        $this->assertSame(0, $this->prepayments()->settleOutstandingRecharges());
        $this->assertSame(2, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
    }

    public function test_the_open_recharge_for_a_schedule_is_the_students_own_unpaid_one(): void
    {
        $student = $this->student();
        $other = $this->student();
        $series = $this->series($student, 2);
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $this->assertNull($this->prepayments()->openRechargeFor($series, $student));

        $recharge = WalletRecharge::query()->create([
            'wallet_id' => $wallet->id, 'user_id' => $student->id, 'amount_minor' => 99800, 'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Requested, 'reference' => 'WRCH-OPEN00000001',
            'metadata' => ['purpose' => BookingSeriesPrepaymentService::PURPOSE, 'booking_series_id' => (string) $series->id, 'booking_ids' => []],
        ]);

        $this->assertTrue($recharge->is($this->prepayments()->openRechargeFor($series, $student)));

        $this->expectException(BookingException::class);
        $this->prepayments()->openRechargeFor($series, $other);
    }

    // ── Phase 2: confirming future classes unattended ──────────────────────

    private function allowAutoSettle(bool $enabled = true): void
    {
        $settings = app(BookingSettings::class);
        $settings->recurring_wallet_auto_settle_enabled = $enabled;
        $settings->save();
    }

    public function test_consent_is_off_by_default_and_never_inferred_from_paying(): void
    {
        // Paying for a batch once says nothing about agreeing to it
        // happening again while the student is away.
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->fundWallet($student, 200000);

        $this->prepayments()->settleFromWallet($series, $student);

        $this->assertFalse((bool) $series->refresh()->auto_settle_from_wallet);
    }

    public function test_nothing_is_settled_without_the_students_consent(): void
    {
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->fundWallet($student, 300000);

        $result = $this->prepayments()->autoSettle($series);

        $this->assertSame(0, $result->paidCount());
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->count());
        $this->assertSame(300000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_nothing_is_settled_while_the_platform_capability_is_off(): void
    {
        // Two switches, because money moving unattended needs both the
        // capability and the permission.
        $this->allowAutoSettle(false);
        $student = $this->student();
        $series = $this->series($student, 3);
        $series->forceFill(['auto_settle_from_wallet' => true])->save();
        $this->fundWallet($student, 300000);

        $result = $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame(0, $result->paidCount());
        $this->assertSame(300000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_with_both_switches_on_future_classes_are_confirmed_from_the_balance(): void
    {
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->prepayments()->setAutoSettle($series, $student, true);
        $this->fundWallet($student, 200000);

        $result = $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame(3, $result->paidCount());
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
    }

    public function test_a_short_balance_charges_nothing_at_all(): void
    {
        // The line between spending what a student deposited for this and
        // charging them: a partial settle would drain the wallet to zero
        // AND leave classes unpaid — the worst of both.
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->prepayments()->setAutoSettle($series, $student, true);
        $this->fundWallet($student, 100000);

        $result = $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame(0, $result->paidCount());
        $this->assertSame(100000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
        $this->assertSame(3, $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->count());
    }

    public function test_auto_settle_never_opens_a_checkout_or_touches_a_card(): void
    {
        // An empty wallet must produce silence, not a payment attempt.
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->prepayments()->setAutoSettle($series, $student, true);

        $result = $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame(0, $result->paidCount());
        $this->assertSame(0, WalletRecharge::query()->count(), 'no top-up may ever be raised unattended');
        $this->assertSame(0, BookingPayment::query()->count());
    }

    public function test_consent_can_always_be_withdrawn_even_when_the_capability_is_off(): void
    {
        // A control that STOPS money moving must never be refusable.
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->prepayments()->setAutoSettle($series, $student, true);

        $this->allowAutoSettle(false);

        $updated = $this->prepayments()->setAutoSettle($series->refresh(), $student, false);

        $this->assertFalse((bool) $updated->auto_settle_from_wallet);
    }

    public function test_consent_cannot_be_granted_while_the_capability_is_off(): void
    {
        $this->allowAutoSettle(false);
        $student = $this->student();
        $series = $this->series($student, 2);

        $this->expectException(BookingException::class);
        $this->prepayments()->setAutoSettle($series, $student, true);
    }

    public function test_consent_cannot_be_set_on_someone_elses_schedule(): void
    {
        $this->allowAutoSettle();
        $series = $this->series($this->student(), 2);

        $this->expectException(BookingException::class);
        $this->prepayments()->setAutoSettle($series, $this->student(), true);
    }

    public function test_auto_settle_leaves_a_mixed_currency_schedule_alone(): void
    {
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->prepayments()->setAutoSettle($series, $student, true);
        $this->fundWallet($student, 300000);

        $series->bookings()->orderBy('starts_at')->first()->forceFill(['currency' => 'USD'])->save();

        $result = $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame(0, $result->paidCount());
        $this->assertSame(300000, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_running_auto_settle_twice_does_not_charge_twice(): void
    {
        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 3);
        $this->prepayments()->setAutoSettle($series, $student, true);
        $this->fundWallet($student, 200000);

        $this->prepayments()->autoSettle($series->refresh());
        $balance = (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor');

        $this->prepayments()->autoSettle($series->refresh());

        $this->assertSame($balance, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_the_student_is_notified_of_each_class_confirmed_from_their_balance(): void
    {
        // Money moving unattended must never be silent. Settlement
        // dispatches BookingPaymentSucceeded, which is the existing
        // participant notification path.
        Event::fake([BookingPaymentSucceeded::class]);

        $this->allowAutoSettle();
        $student = $this->student();
        $series = $this->series($student, 2);
        $this->prepayments()->setAutoSettle($series, $student, true);
        $this->fundWallet($student, 200000);

        $this->prepayments()->autoSettle($series->refresh());

        Event::assertDispatchedTimes(BookingPaymentSucceeded::class, 2);
    }

    public function test_the_generation_job_confirms_newly_created_classes(): void
    {
        // End to end: the pass that fills the schedule forward is what
        // makes this useful — new classes arrive already confirmed.
        $this->allowAutoSettle();
        $student = $this->student();

        // Two classes already reserved, a third still to be generated —
        // the state the hourly pass actually runs in.
        $series = $this->series($student, 2);
        $series->forceFill([
            'occurrence_count' => 3,
            'generated_through_date' => $series->bookings()->orderByDesc('starts_at')->first()->series_occurrence_date,
        ])->save();

        $this->prepayments()->setAutoSettle($series->refresh(), $student, true);
        $this->fundWallet($student, 300000);

        app(GenerateBookingSeriesOccurrences::class, ['bookingSeriesId' => (string) $series->id])
            ->handle(app(BookingSeriesService::class), $this->prepayments());

        $this->assertSame(3, $series->bookings()->count(), 'the pass should have created the third class');
        $this->assertSame(
            0,
            $series->bookings()->where('payment_status', BookingPaymentStatus::Pending)->count(),
            'classes the pass created should already be confirmed from the balance',
        );
    }

    // ── What is deliberately NOT collected ─────────────────────────────────

    public function test_planned_classes_beyond_the_horizon_are_never_charged_for(): void
    {
        // The schedule is for 10 classes but only 3 are reserved. Money
        // is never taken for a class nobody is holding.
        $student = $this->student();
        $series = $this->series($student, 3);
        $series->forceFill(['occurrence_count' => 10])->save();

        $quote = $this->prepayments()->quote($series->refresh(), $student);

        $this->assertSame(3, $quote->count());
        $this->assertSame(149700, $quote->totalMinor);
        $this->assertGreaterThan(0, $quote->plannedCount);
    }
}
