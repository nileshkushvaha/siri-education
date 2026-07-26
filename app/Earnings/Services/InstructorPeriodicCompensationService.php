<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Events\InstructorEarningCreated;
use App\Earnings\Support\CompensationMath;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationPeriod;
use App\Models\InstructorEarning;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accrues daily/weekly/monthly base compensation: one immutable
 * compensation-period record and exactly one instructor earning per
 * CLOSED period, computed on agreement-timezone boundaries (days at
 * 00:00, ISO Monday weeks, calendar months). Idempotent by owner lock +
 * the icp_agreement_period_unique / ie_source_unique constraints —
 * command retries can never duplicate money. Gated by earnings_enabled;
 * future incomplete periods are never accrued; ineligible (suspended /
 * archived / role-stripped) instructors are skipped with an audit
 * entry. The resulting earnings enter the normal hold → release
 * lifecycle and are reservable by withdrawals like any other earning.
 * Amounts come from the agreement alone — no student pricing input
 * exists on this path at all.
 */
final class InstructorPeriodicCompensationService implements InstructorPeriodicCompensationServiceInterface
{
    private const string LOG_NAME = 'instructor_compensation';

    /** Runaway guard: max periods accrued per agreement per run. */
    private const int MAX_PERIODS_PER_RUN = 400;

    public function __construct(
        private readonly InstructorCompensationAgreementServiceInterface $agreements,
        private readonly InstructorPayoutEligibility $eligibility,
        private readonly InstructorEarningSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function accrueClosedPeriods(): int
    {
        if (! $this->settings->earnings_enabled) {
            $this->audit->logSystem(self::LOG_NAME, 'accrual_skipped_disabled', 'Periodic compensation accrual skipped: earnings are disabled.');

            return 0;
        }

        // Periodic compensation has its own rollout gate — while off,
        // accrual creates nothing even with earnings enabled.
        if (! $this->settings->periodic_compensation_enabled) {
            $this->audit->logSystem(self::LOG_NAME, 'accrual_skipped_disabled', 'Periodic compensation accrual skipped: periodic compensation is not enabled.');

            return 0;
        }

        $accrued = 0;

        $instructorIds = InstructorCompensationAgreement::query()
            ->whereIn('status', ['active', 'scheduled'])
            ->where('pay_basis', '!=', CompensationPayBasis::Hourly)
            ->distinct()
            ->pluck('instructor_id');

        foreach ($instructorIds as $instructorId) {
            $accrued += $this->accrueForInstructor((int) $instructorId);
        }

        return $accrued;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function accrueForInstructor(int $instructorId): int
    {
        $created = [];

        DB::transaction(function () use ($instructorId, &$created): void {
            // Canonical lock order: owner row first — accrual serializes
            // with agreement changes, withdrawals, and settlement.
            $instructor = User::query()->whereKey($instructorId)->lockForUpdate()->first();

            if ($instructor === null) {
                return;
            }

            $this->agreements->syncLifecycle($instructorId, now());

            $agreement = InstructorCompensationAgreement::query()
                ->forInstructor($instructorId)
                ->active()
                ->where('pay_basis', '!=', CompensationPayBasis::Hourly)
                ->lockForUpdate()
                ->first();

            if ($agreement === null) {
                return;
            }

            if (($reason = $this->eligibility->reasonForIneligibility($instructor)) !== null) {
                $this->audit->logSystem(self::LOG_NAME, 'accrual_skipped_ineligible', sprintf('Periodic accrual skipped for agreement %s: %s', $agreement->reference, $reason), $agreement);

                return;
            }

            foreach ($this->closedUnaccruedPeriods($agreement) as [$start, $end]) {
                $created[] = $this->accruePeriod($agreement, $start, $end);
            }
        });

        // Events and their notifications only after commit.
        foreach ($created as $earning) {
            InstructorEarningCreated::dispatch($earning);
        }

        return count($created);
    }

    /**
     * Closed, un-accrued periods from the agreement start (or the last
     * accrued period) up to now, in the agreement timezone. A period is
     * closed when its exclusive end boundary has passed; the current
     * (incomplete) period is never returned.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> [periodStart, periodEnd] local dates
     */
    private function closedUnaccruedPeriods(InstructorCompensationAgreement $agreement): array
    {
        $timezone = $agreement->timezone;
        $now = CarbonImmutable::now($timezone);
        $basis = $agreement->pay_basis;

        $lastAccrued = $agreement->periods()->max('period_end');

        $cursor = $lastAccrued !== null
            ? CarbonImmutable::parse($lastAccrued, $timezone)->addDay()->startOfDay()
            : $agreement->effective_from->setTimezone($timezone)->startOfDay();

        $windowEnd = $agreement->effective_until?->setTimezone($timezone);

        $periods = [];

        while (count($periods) < self::MAX_PERIODS_PER_RUN) {
            [$start, $end] = match ($basis) {
                CompensationPayBasis::Daily => [$cursor, $cursor],
                CompensationPayBasis::Weekly => [$cursor, $cursor->addDays(6)],
                CompensationPayBasis::Monthly => [$cursor, $cursor->endOfMonth()->startOfDay()],
                default => throw new \LogicException('Hourly agreements are never accrued periodically.'),
            };

            // Exclusive close boundary: the instant after the period.
            $closesAt = $end->addDay()->startOfDay();

            if ($closesAt > $now) {
                break; // Current period still open — never accrue early.
            }

            if ($windowEnd !== null && $closesAt > $windowEnd) {
                break; // Agreement ended before this period completed.
            }

            $periods[] = [$start, $end];
            $cursor = $closesAt;
        }

        return $periods;
    }

    private function accruePeriod(InstructorCompensationAgreement $agreement, CarbonImmutable $start, CarbonImmutable $end): InstructorEarning
    {
        $period = InstructorCompensationPeriod::query()->create([
            'agreement_id' => $agreement->id,
            'instructor_id' => $agreement->instructor_id,
            'pay_basis' => $agreement->pay_basis,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'timezone' => $agreement->timezone,
            'amount_minor' => $agreement->amount_minor,
            'currency_id' => $agreement->currency_id,
            'currency_code' => $agreement->currency_code,
            'accrued_at' => now(),
        ]);

        $earning = InstructorEarning::query()->create([
            'lesson_id' => null,
            'booking_id' => null,
            'instructor_id' => $agreement->instructor_id,
            'student_id' => null,
            'currency_id' => $agreement->currency_id,
            'currency_code' => $agreement->currency_code,
            'earning_amount_minor' => $agreement->amount_minor,
            'calculation_type' => EarningCalculationType::Periodic,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => $end->addDay()->startOfDay()->setTimezone(config('app.timezone'))->addDays($this->settings->hold_days),
            'source_type' => 'periodic_compensation',
            'source_id' => $period->id,
            'metadata' => [
                'agreement_id' => $agreement->id,
                'agreement_reference' => $agreement->reference,
                'agreement_version' => $agreement->version,
                'pay_basis' => $agreement->pay_basis->value,
                'rate_minor' => $agreement->amount_minor,
                'rounding_policy' => CompensationMath::ROUNDING_POLICY,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'timezone' => $agreement->timezone,
                'calculated_at' => now()->toIso8601String(),
            ],
        ]);

        $this->audit->logSystem(self::LOG_NAME, 'periodic_earning_accrued', sprintf('Periodic earning of %d %s accrued for agreement %s (%s → %s).', $agreement->amount_minor, $agreement->currency_code, $agreement->reference, $start->toDateString(), $end->toDateString()), $earning, [
            'agreement_reference' => $agreement->reference,
            'amount_minor' => $agreement->amount_minor,
            'currency' => $agreement->currency_code,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]);

        return $earning;
    }
}
