<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorCompensationResolverInterface;
use App\Earnings\DTOs\CompensationResolution;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Exceptions\CompensationBlockedException;
use App\Earnings\Support\CompensationMath;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The active compensation resolver. The canonical resolution timestamp
 * is the lesson's SCHEDULED START — the agreement
 * in force when the service was delivered pays for it, no matter when
 * the instructor marks completion, when auto-completion fires, or when
 * the queue processes the event. Resolution therefore searches the
 * approved agreement lineage (active AND ended AND due-scheduled rows)
 * for the window covering that instant; drafts and cancellations never
 * pay. Blocks are categorized (CompensationBlockedException) so the
 * exception queue and retry sweep can recover them; benign skips
 * (periodic basis, demo policy none) return null with an audit entry.
 * There is still no percentage path, no student-price input, no silent
 * global amount, and no zero-earning guess.
 */
final class InstructorCompensationResolver implements InstructorCompensationResolverInterface
{
    private const string LOG_NAME = 'instructor_compensation';

    public function __construct(
        private readonly InstructorCompensationAgreementServiceInterface $agreements,
        private readonly InstructorEarningSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function resolveForLesson(Lesson $lesson): ?CompensationResolution
    {
        // Canonical timestamp: scheduled start of the delivered service.
        $at = CarbonImmutable::instance($lesson->starts_at ?? $lesson->completed_at ?? now());

        return DB::transaction(function () use ($lesson, $at): ?CompensationResolution {
            // Owner lock: agreement resolution serializes with agreement
            // lifecycle changes for this instructor.
            User::query()->whereKey($lesson->instructor_id)->lockForUpdate()->first();

            $this->agreements->syncLifecycle($lesson->instructor_id, now());

            $agreement = $this->agreementCovering($lesson->instructor_id, $at);

            if ($agreement === null) {
                throw new CompensationBlockedException(
                    CompensationExceptionCategory::MissingAgreement,
                    'No compensation agreement covers the scheduled lesson time. Configure (or backdate) an agreement, then the lesson can be retried.',
                );
            }

            $this->assertAgreementUsable($agreement);

            if ($agreement->pay_basis->isPeriodic()) {
                // Base pay accrues per closed period; a lesson under a
                // periodic agreement creates no additional base earning.
                $this->audit->logSystem(self::LOG_NAME, 'earning_skipped_periodic_basis', sprintf('Lesson %s creates no lesson earning: agreement %s pays %s.', $lesson->id, $agreement->reference, $agreement->pay_basis->value), $lesson);

                return null;
            }

            if ($this->isDemoLesson($lesson)) {
                return $this->resolveDemo($lesson, $agreement, $at);
            }

            return $this->resolveHourly($lesson, $agreement, $at);
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * The approved agreement whose effective window covers the instant:
     * active or ended (historical windows stay payable for service
     * delivered inside them) or a due scheduled successor. Draft and
     * cancelled agreements never pay. Most recent window start wins.
     */
    private function agreementCovering(int $instructorId, CarbonImmutable $at): ?InstructorCompensationAgreement
    {
        return InstructorCompensationAgreement::query()
            ->forInstructor($instructorId)
            ->whereIn('status', [
                CompensationAgreementStatus::Active,
                CompensationAgreementStatus::Ended,
                CompensationAgreementStatus::Scheduled,
            ])
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
    }

    private function assertAgreementUsable(InstructorCompensationAgreement $agreement): void
    {
        if ($agreement->amount_minor <= 0) {
            throw new CompensationBlockedException(
                CompensationExceptionCategory::InvalidAgreement,
                sprintf('Agreement %s has a non-positive amount and cannot pay.', $agreement->reference),
            );
        }

        $currencyActive = Currency::query()->active()->where('code', $agreement->currency_code)->exists();

        if (! $currencyActive) {
            throw new CompensationBlockedException(
                CompensationExceptionCategory::InvalidCurrency,
                sprintf('Agreement %s uses currency %s, which is not active.', $agreement->reference, $agreement->currency_code),
            );
        }
    }

    private function resolveHourly(Lesson $lesson, InstructorCompensationAgreement $agreement, CarbonImmutable $at): CompensationResolution
    {
        $minutes = $this->eligibleMinutes($lesson);

        $override = $agreement->overrides()
            ->where(fn ($q) => $q->whereNull('subject_id')->orWhere('subject_id', $lesson->subject_id))
            ->where(fn ($q) => $q->whereNull('academic_level_id')->orWhere('academic_level_id', $lesson->academic_level_id))
            ->where(fn ($q) => $q->whereNull('duration_minutes')->orWhere('duration_minutes', $minutes))
            ->get()
            // Most specific dimensions win: subject (4) > level (2) > duration (1).
            ->sortByDesc(fn ($candidate) => $candidate->specificity())
            ->first();

        if ($override !== null) {
            $this->audit->logSystem(self::LOG_NAME, 'compensation_override_applied', sprintf('Override %s applied for lesson %s on agreement %s.', $override->id, $lesson->id, $agreement->reference), $lesson, ['override_id' => $override->id, 'amount_minor' => $override->amount_minor]);
        }

        $rate = $override?->amount_minor ?? $agreement->amount_minor;

        return new CompensationResolution(
            agreementId: $agreement->id,
            agreementReference: $agreement->reference,
            agreementVersion: $agreement->version,
            payBasis: CompensationPayBasis::Hourly,
            calculationType: EarningCalculationType::Hourly,
            rateMinor: $rate,
            amountMinor: CompensationMath::hourlyAmount($rate, $minutes),
            currencyId: $agreement->currency_id,
            currencyCode: $agreement->currency_code,
            resolvedAt: $at,
            eligibleMinutes: $minutes,
            overrideId: $override?->id,
        );
    }

    private function resolveDemo(Lesson $lesson, InstructorCompensationAgreement $agreement, CarbonImmutable $at): ?CompensationResolution
    {
        $policy = $this->settings->demo_compensation_policy;
        $fixed = $this->settings->demo_fixed_amount_minor;

        if ($policy !== 'fixed_demo_amount' || $fixed === null || $fixed <= 0) {
            $this->audit->logSystem(self::LOG_NAME, 'earning_skipped_demo_policy', sprintf('Demo lesson %s creates no earning under demo policy "%s".', $lesson->id, $policy), $lesson);

            return null;
        }

        return new CompensationResolution(
            agreementId: $agreement->id,
            agreementReference: $agreement->reference,
            agreementVersion: $agreement->version,
            payBasis: $agreement->pay_basis,
            calculationType: EarningCalculationType::DemoFixed,
            rateMinor: $fixed,
            amountMinor: $fixed,
            currencyId: $agreement->currency_id,
            currencyCode: $agreement->currency_code,
            resolvedAt: $at,
            eligibleMinutes: $this->eligibleMinutes($lesson),
        );
    }

    /** Free/demo bookings are those the platform requires no payment for. */
    private function isDemoLesson(Lesson $lesson): bool
    {
        return $lesson->booking?->payment_status === BookingPaymentStatus::NotRequired;
    }

    private function eligibleMinutes(Lesson $lesson): int
    {
        $minutes = (int) $lesson->starts_at->diffInMinutes($lesson->ends_at, absolute: false);

        if ($minutes <= 0 || $minutes > 480) {
            throw new CompensationBlockedException(
                CompensationExceptionCategory::UnsupportedDuration,
                sprintf('Lesson duration of %d minute(s) is outside the supported range (1–480).', $minutes),
            );
        }

        return $minutes;
    }
}
