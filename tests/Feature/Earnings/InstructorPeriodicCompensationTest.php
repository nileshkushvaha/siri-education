<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationPeriod;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 14.2 §10 — periodic (daily/weekly/monthly) accrual: one earning
 * per closed period in the agreement timezone, idempotent across
 * retries, kill-switch gated, and fully independent of lessons and
 * student pricing.
 */
class InstructorPeriodicCompensationTest extends TestCase
{
    use RefreshDatabase;

    private InstructorPeriodicCompensationServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InstructorPeriodicCompensationServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->earnings_enabled = true;
        // Phase 14.3: periodic accrual has its own rollout gate — these
        // tests exercise the accrual mechanics, so both switches are on.
        $settings->periodic_compensation_enabled = true;
        $settings->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_accrual_creates_one_earning_per_closed_day(): void
    {
        $agreement = $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(3));

        $accrued = $this->service->accrueClosedPeriods();

        // Three closed days; today is open and never accrued early.
        $this->assertSame(3, $accrued);
        $this->assertSame(3, InstructorCompensationPeriod::query()->where('agreement_id', $agreement->id)->count());

        $earnings = InstructorEarning::query()->where('instructor_id', $agreement->instructor_id)->get();
        $this->assertCount(3, $earnings);

        foreach ($earnings as $earning) {
            $this->assertSame(200000, $earning->earning_amount_minor);
            $this->assertSame(EarningCalculationType::Periodic, $earning->calculation_type);
            $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
            $this->assertNull($earning->lesson_id);
            $this->assertNull($earning->getAttribute('student_amount_minor'));
            $this->assertSame('periodic_compensation', $earning->source_type);
        }
    }

    public function test_retry_is_idempotent(): void
    {
        $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(2));

        $this->assertSame(2, $this->service->accrueClosedPeriods());
        $this->assertSame(0, $this->service->accrueClosedPeriods());

        $this->artisan('instructor-earnings:accrue-periodic-compensation')
            ->expectsOutputToContain('Accrued 0 period(s).')
            ->assertSuccessful();

        $this->assertSame(2, InstructorEarning::query()->count());
    }

    public function test_weekly_accrual_uses_iso_monday_weeks(): void
    {
        $agreement = $this->agreement('weekly', 1000000, CarbonImmutable::now('Asia/Kolkata')->startOfWeek()->subWeeks(2));

        $this->assertSame(2, $this->service->accrueClosedPeriods());

        $period = InstructorCompensationPeriod::query()->where('agreement_id', $agreement->id)->orderBy('period_start')->first();
        $this->assertSame('Monday', CarbonImmutable::parse($period->period_start)->format('l'));
        $this->assertSame('Sunday', CarbonImmutable::parse($period->period_end)->format('l'));
        $this->assertSame(6, (int) CarbonImmutable::parse($period->period_start)->diffInDays(CarbonImmutable::parse($period->period_end)));
    }

    public function test_monthly_accrual_uses_calendar_months(): void
    {
        $agreement = $this->agreement('monthly', 4000000, CarbonImmutable::now('Asia/Kolkata')->startOfMonth()->subMonthsNoOverflow(2));

        $this->assertSame(2, $this->service->accrueClosedPeriods());

        $period = InstructorCompensationPeriod::query()->where('agreement_id', $agreement->id)->orderBy('period_start')->first();
        $this->assertSame(1, CarbonImmutable::parse($period->period_start)->day);
        $this->assertTrue(CarbonImmutable::parse($period->period_end)->isLastOfMonth());
        $this->assertSame(4000000, InstructorEarning::query()->where('instructor_id', $agreement->instructor_id)->first()->earning_amount_minor);
    }

    public function test_agreement_timezone_controls_day_boundaries(): void
    {
        // 20:00 UTC = 01:30 the NEXT day in Asia/Kolkata: the Kolkata day
        // has closed even though the UTC day has not.
        Carbon::setTestNow(Carbon::parse('2026-07-10 20:30:00', 'UTC'));

        $this->agreement('daily', 200000, CarbonImmutable::parse('2026-07-10 00:00:00', 'Asia/Kolkata'));

        // In Kolkata it is already July 11 → July 10 is a closed day.
        $this->assertSame(1, $this->service->accrueClosedPeriods());

        // A UTC-zone agreement starting the same UTC date has no closed day yet.
        $utcAgreement = $this->agreement('daily', 200000, CarbonImmutable::parse('2026-07-10 00:00:00', 'UTC'), timezone: 'UTC');
        $this->assertSame(0, $this->service->accrueClosedPeriods());
        $this->assertSame(0, InstructorCompensationPeriod::query()->where('agreement_id', $utcAgreement->id)->count());
    }

    public function test_kill_switch_blocks_accrual_including_the_command(): void
    {
        $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(2));

        $settings = app(InstructorEarningSettings::class);
        $settings->earnings_enabled = false;
        $settings->save();

        $this->assertSame(0, $this->service->accrueClosedPeriods());

        $this->artisan('instructor-earnings:accrue-periodic-compensation')
            ->expectsOutputToContain('Accrued 0 period(s).')
            ->assertSuccessful();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'accrual_skipped_disabled']);
    }

    public function test_inactive_agreements_and_ineligible_instructors_are_skipped(): void
    {
        // Draft agreement — never accrued.
        $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(2), status: 'draft');
        $this->assertSame(0, $this->service->accrueClosedPeriods());

        // Suspended instructor — skipped safely with an audit entry.
        $agreement = $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(2));
        $agreement->instructor->update(['status' => User::STATUS_SUSPENDED]);

        $this->assertSame(0, $this->service->accrueClosedPeriods());
        $this->assertDatabaseHas('activity_log', ['event' => 'accrual_skipped_ineligible']);
    }

    public function test_periodic_earnings_enter_hold_release_and_phase15_reservation(): void
    {
        $agreement = $this->agreement('daily', 200000, CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(10));

        $this->service->accrueClosedPeriods();

        $earning = InstructorEarning::query()
            ->where('instructor_id', $agreement->instructor_id)
            ->orderBy('hold_until')
            ->firstOrFail();

        // Oldest period's hold (period end + 1 day + 7 hold days) has
        // lapsed → the release sweep promotes it.
        $this->artisan('instructor-earnings:release')->assertSuccessful();
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->fresh()->status);

        // …and Phase 15 can reserve it like any other earning.
        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        $settings->save();

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $agreement->instructor_id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        $request = app(InstructorWithdrawalServiceInterface::class)
            ->requestWithdrawal($agreement->instructor, $method, 100000);

        $this->assertSame(100000, (int) $request->allocations()->sum('amount_minor'));

        $settings->withdrawals_enabled = false;
        $settings->save();
    }

    public function test_periodic_agreement_never_creates_hourly_lesson_earnings(): void
    {
        // Covered end-to-end in InstructorEarningTest
        // (test_periodic_agreement_creates_no_lesson_earning); here we
        // assert the accrual side never touches lesson sources.
        $this->agreement('weekly', 1000000, CarbonImmutable::now('Asia/Kolkata')->startOfWeek()->subWeeks(1));

        $this->service->accrueClosedPeriods();

        $this->assertSame(0, InstructorEarning::query()->where('source_type', 'lesson')->count());
        $this->assertSame(1, InstructorEarning::query()->where('source_type', 'periodic_compensation')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function agreement(
        string $basis,
        int $amountMinor,
        CarbonImmutable $effectiveFrom,
        string $status = 'active',
        string $timezone = 'Asia/Kolkata',
    ): InstructorCompensationAgreement {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update(['instructor_status' => InstructorStatus::Active]);

        return InstructorCompensationAgreement::factory()->create([
            'instructor_id' => $instructor->id,
            'pay_basis' => $basis,
            'amount_minor' => $amountMinor,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'timezone' => $timezone,
            'status' => $status,
            'effective_from' => $effectiveFrom,
            'approved_at' => $status === 'active' ? now() : null,
        ]);
    }
}
