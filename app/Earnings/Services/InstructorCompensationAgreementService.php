<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\CompensationException;
use App\Models\AcademicLevel;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationOverride;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the compensation-agreement lifecycle. The amount is always an
 * explicit administrator decision — no formula reads student price,
 * experience fields, or ratings. Version 1 boundary rules: hourly
 * agreements may start any time; daily agreements start on a day
 * boundary, weekly on an ISO Monday, monthly on the 1st — all in the
 * agreement timezone; periodic agreements end on a matching boundary.
 * Partial-period corrections are future audited adjustments, never a
 * hidden proration formula. Active financial terms are immutable —
 * changes create a replacement (version chain). Nothing is ever
 * hard-deleted; ending or replacing an agreement never touches
 * historical earnings.
 */
final class InstructorCompensationAgreementService implements InstructorCompensationAgreementServiceInterface
{
    private const string LOG_NAME = 'instructor_compensation';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly InstructorEarningSettings $settings,
    ) {}

    /**
     * Daily/weekly/monthly pay fixed contractual amounts per period
     * regardless of taught lessons — that rollout is gated behind
     * periodic_compensation_enabled until its attendance/leave rules are
     * defined. Drafting stays possible (preparation), activation does not.
     */
    private function assertBasisEnabled(CompensationPayBasis $basis): void
    {
        if ($basis->isPeriodic() && ! $this->settings->periodic_compensation_enabled) {
            throw new CompensationException('Daily, weekly, and monthly compensation is not enabled on this platform yet. Only hourly agreements can be activated.');
        }
    }

    public function createDraft(
        User $admin,
        User $instructor,
        CompensationPayBasis $basis,
        int $amountMinor,
        string $currencyCode,
        string $timezone,
        DateTimeInterface $effectiveFrom,
        string $internalReason,
        ?string $notes = null,
    ): InstructorCompensationAgreement {
        if (! $instructor->hasRole('instructor')) {
            throw new CompensationException('Compensation agreements can only be created for instructors.');
        }

        if ($amountMinor <= 0) {
            throw new CompensationException('The agreed amount must be positive.');
        }

        if (trim($internalReason) === '') {
            throw new CompensationException('An internal reason is required.');
        }

        $currencyCode = strtoupper(trim($currencyCode));

        if (! Currency::query()->active()->where('code', $currencyCode)->exists()) {
            throw new CompensationException('This currency is not supported.');
        }

        $timezone = $this->validateTimezone($timezone);
        $effectiveFrom = $this->assertBoundary($basis, $effectiveFrom, $timezone, 'start');

        $agreement = new InstructorCompensationAgreement([
            'reference' => $this->generateReference(),
            'instructor_id' => $instructor->id,
            'pay_basis' => $basis,
            'amount_minor' => $amountMinor,
            'currency_id' => Currency::query()->where('code', $currencyCode)->value('id'),
            'currency_code' => $currencyCode,
            'timezone' => $timezone,
            'effective_from' => $effectiveFrom,
            'internal_reason' => $internalReason,
            'notes' => $notes,
            'created_by' => $admin->id,
        ]);

        $agreement->forceFill(['status' => CompensationAgreementStatus::Draft, 'version' => 1])->save();

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_drafted', sprintf('Compensation agreement %s drafted (%s).', $agreement->reference, $basis->shortLabel()), $agreement, $this->safeMeta($agreement));

        return $agreement;
    }

    public function addOverride(
        InstructorCompensationAgreement $agreement,
        User $admin,
        ?string $subjectId,
        ?string $academicLevelId,
        ?int $durationMinutes,
        int $amountMinor,
    ): InstructorCompensationOverride {
        if ($agreement->pay_basis !== CompensationPayBasis::Hourly) {
            throw new CompensationException('Rate overrides apply to hourly agreements only.');
        }

        if (! $agreement->status->isEditable()) {
            throw new CompensationException('Overrides can only be changed while the agreement is draft or scheduled.');
        }

        if ($amountMinor <= 0) {
            throw new CompensationException('The override amount must be positive.');
        }

        if ($subjectId === null && $academicLevelId === null && $durationMinutes === null) {
            throw new CompensationException('An override needs at least one dimension (subject, level, or duration).');
        }

        if ($subjectId !== null && ! Subject::query()->whereKey($subjectId)->exists()) {
            throw new CompensationException('Unknown subject.');
        }

        if ($academicLevelId !== null && ! AcademicLevel::query()->whereKey($academicLevelId)->exists()) {
            throw new CompensationException('Unknown education level.');
        }

        if ($durationMinutes !== null && ($durationMinutes < 15 || $durationMinutes > 480)) {
            throw new CompensationException('The duration dimension must be between 15 and 480 minutes.');
        }

        $comboKey = InstructorCompensationOverride::comboKey($subjectId, $academicLevelId, $durationMinutes);

        if ($agreement->overrides()->where('combo_key', $comboKey)->exists()) {
            throw new CompensationException('An override for this exact combination already exists.');
        }

        $override = $agreement->overrides()->create([
            'subject_id' => $subjectId,
            'academic_level_id' => $academicLevelId,
            'duration_minutes' => $durationMinutes,
            'amount_minor' => $amountMinor,
            'combo_key' => $comboKey,
        ]);

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_override_added', sprintf('Override added to agreement %s.', $agreement->reference), $agreement, ['override_id' => $override->id, 'amount_minor' => $amountMinor]);

        return $override;
    }

    public function removeOverride(InstructorCompensationOverride $override, User $admin): void
    {
        $agreement = $override->agreement;

        if (! $agreement->status->isEditable()) {
            throw new CompensationException('Overrides can only be changed while the agreement is draft or scheduled.');
        }

        $override->delete();

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_override_removed', sprintf('Override removed from agreement %s.', $agreement->reference), $agreement, ['override_id' => $override->id]);
    }

    public function schedule(InstructorCompensationAgreement $agreement, User $admin): InstructorCompensationAgreement
    {
        $this->assertBasisEnabled($agreement->pay_basis);

        $agreement = DB::transaction(function () use ($agreement): InstructorCompensationAgreement {
            User::query()->whereKey($agreement->instructor_id)->lockForUpdate()->first();
            $agreement = $this->locked($agreement);

            $this->assertNoOverlap($agreement);

            return $this->transition($agreement, CompensationAgreementStatus::Scheduled);
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_scheduled', sprintf('Compensation agreement %s scheduled from %s.', $agreement->reference, $agreement->effective_from->toDateString()), $agreement, $this->safeMeta($agreement));

        return $agreement;
    }

    public function activate(InstructorCompensationAgreement $agreement, User $admin, string $reason): InstructorCompensationAgreement
    {
        if (trim($reason) === '') {
            throw new CompensationException('An internal reason is required to activate an agreement.');
        }

        $this->assertBasisEnabled($agreement->pay_basis);

        $agreement = DB::transaction(function () use ($agreement, $admin): InstructorCompensationAgreement {
            // Canonical lock order: instructor owner row first.
            User::query()->whereKey($agreement->instructor_id)->lockForUpdate()->first();
            $agreement = $this->locked($agreement);

            $this->syncLifecycle($agreement->instructor_id, now());
            $agreement->refresh();

            // syncLifecycle may already have promoted this very agreement
            // (scheduled + window open) — then only the approval stamp is
            // missing, not a transition.
            if ($agreement->status === CompensationAgreementStatus::Active) {
                $agreement->forceFill([
                    'approved_by' => $agreement->approved_by ?? $admin->id,
                    'approved_at' => $agreement->approved_at ?? now(),
                ])->save();

                return $agreement;
            }

            $this->assertNoOverlap($agreement);

            if (InstructorCompensationAgreement::query()->forInstructor($agreement->instructor_id)->active()->whereKeyNot($agreement->id)->exists()) {
                throw new CompensationException('Another agreement is already active for this instructor — end or replace it first.');
            }

            return $this->transition($agreement, CompensationAgreementStatus::Active, [
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_activated', sprintf('Compensation agreement %s activated.', $agreement->reference), $agreement, [...$this->safeMeta($agreement), 'reason' => $reason]);

        return $agreement;
    }

    public function end(InstructorCompensationAgreement $agreement, User $admin, DateTimeInterface $effectiveUntil, string $reason): InstructorCompensationAgreement
    {
        if (trim($reason) === '') {
            throw new CompensationException('An internal reason is required to end an agreement.');
        }

        $agreement = DB::transaction(function () use ($agreement, $admin, $effectiveUntil): InstructorCompensationAgreement {
            User::query()->whereKey($agreement->instructor_id)->lockForUpdate()->first();
            $agreement = $this->locked($agreement);

            if ($agreement->status !== CompensationAgreementStatus::Active) {
                throw new CompensationException('Only an active agreement can be ended.');
            }

            $effectiveUntil = $this->assertBoundary($agreement->pay_basis, $effectiveUntil, $agreement->timezone, 'end');

            if ($effectiveUntil <= $agreement->effective_from) {
                throw new CompensationException('The end date must be after the effective start.');
            }

            $agreement->forceFill(['effective_until' => $effectiveUntil])->save();

            // Past-dated end takes effect immediately; a future end is
            // settled lazily by syncLifecycle.
            if ($effectiveUntil <= now()) {
                $agreement = $this->transition($agreement, CompensationAgreementStatus::Ended, [
                    'ended_by' => $admin->id,
                    'ended_at' => now(),
                ]);
            }

            return $agreement;
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_ended', sprintf('Compensation agreement %s ends %s.', $agreement->reference, $agreement->effective_until->toDateTimeString()), $agreement, [...$this->safeMeta($agreement), 'reason' => $reason]);

        return $agreement;
    }

    public function cancel(InstructorCompensationAgreement $agreement, User $admin, ?string $reason = null): InstructorCompensationAgreement
    {
        $agreement = DB::transaction(function () use ($agreement, $admin): InstructorCompensationAgreement {
            User::query()->whereKey($agreement->instructor_id)->lockForUpdate()->first();

            return $this->transition($this->locked($agreement), CompensationAgreementStatus::Cancelled, [
                'cancelled_by' => $admin->id,
                'cancelled_at' => now(),
            ]);
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_cancelled', sprintf('Compensation agreement %s cancelled.', $agreement->reference), $agreement, array_filter([...$this->safeMeta($agreement), 'reason' => $reason]));

        return $agreement;
    }

    public function replace(
        InstructorCompensationAgreement $agreement,
        User $admin,
        CompensationPayBasis $basis,
        int $amountMinor,
        string $currencyCode,
        DateTimeInterface $effectiveFrom,
        string $reason,
        ?string $notes = null,
    ): InstructorCompensationAgreement {
        if (trim($reason) === '') {
            throw new CompensationException('An internal reason is required to replace an agreement.');
        }

        $this->assertBasisEnabled($basis);

        if ($amountMinor <= 0) {
            throw new CompensationException('The agreed amount must be positive.');
        }

        $currencyCode = strtoupper(trim($currencyCode));

        if (! Currency::query()->active()->where('code', $currencyCode)->exists()) {
            throw new CompensationException('This currency is not supported.');
        }

        $replacement = DB::transaction(function () use ($agreement, $admin, $basis, $amountMinor, $currencyCode, $effectiveFrom, $reason, $notes): InstructorCompensationAgreement {
            User::query()->whereKey($agreement->instructor_id)->lockForUpdate()->first();
            $agreement = $this->locked($agreement);

            if ($agreement->status !== CompensationAgreementStatus::Active) {
                throw new CompensationException('Only an active agreement can be replaced.');
            }

            // The cutover must satisfy BOTH boundary rules: it ends the
            // old basis and starts the new one.
            $cutover = $this->assertBoundary($agreement->pay_basis, $effectiveFrom, $agreement->timezone, 'end');
            $cutover = $this->assertBoundary($basis, $cutover, $agreement->timezone, 'start');

            if ($cutover <= $agreement->effective_from) {
                throw new CompensationException('The replacement must start after the current agreement started.');
            }

            $agreement->forceFill(['effective_until' => $cutover])->save();

            $replacement = new InstructorCompensationAgreement([
                'reference' => $this->generateReference(),
                'instructor_id' => $agreement->instructor_id,
                'pay_basis' => $basis,
                'amount_minor' => $amountMinor,
                'currency_id' => Currency::query()->where('code', $currencyCode)->value('id'),
                'currency_code' => $currencyCode,
                'timezone' => $agreement->timezone,
                'effective_from' => $cutover,
                'internal_reason' => $reason,
                'notes' => $notes,
                'created_by' => $admin->id,
            ]);

            $replacement->forceFill([
                'status' => CompensationAgreementStatus::Scheduled,
                'version' => $agreement->version + 1,
                'supersedes_agreement_id' => $agreement->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ])->save();

            // A past/now cutover takes effect immediately.
            $this->syncLifecycle($agreement->instructor_id, now());

            return $replacement->refresh();
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'agreement_replaced', sprintf('Compensation agreement %s replaced by %s (v%d).', $agreement->reference, $replacement->reference, $replacement->version), $replacement, [...$this->safeMeta($replacement), 'old_agreement_id' => $agreement->id, 'new_agreement_id' => $replacement->id, 'reason' => $reason]);

        return $replacement;
    }

    public function syncLifecycle(int $instructorId, DateTimeInterface $now): void
    {
        // Expire first (frees the single-active slot), then promote.
        InstructorCompensationAgreement::query()
            ->forInstructor($instructorId)
            ->active()
            ->whereNotNull('effective_until')
            ->where('effective_until', '<=', $now)
            ->get()
            ->each(function (InstructorCompensationAgreement $stale): void {
                $this->transition($stale, CompensationAgreementStatus::Ended, ['ended_at' => now()]);
                $this->audit->logSystem(self::LOG_NAME, 'agreement_ended', sprintf('Compensation agreement %s reached its end date.', $stale->reference), $stale, $this->safeMeta($stale));
            });

        if (InstructorCompensationAgreement::query()->forInstructor($instructorId)->active()->exists()) {
            return;
        }

        $due = InstructorCompensationAgreement::query()
            ->forInstructor($instructorId)
            ->where('status', CompensationAgreementStatus::Scheduled)
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            // Periodic bases stay parked while their rollout gate is off.
            ->when(! $this->settings->periodic_compensation_enabled, fn ($q) => $q->where('pay_basis', CompensationPayBasis::Hourly))
            ->orderBy('effective_from')
            ->first();

        if ($due !== null) {
            $this->transition($due, CompensationAgreementStatus::Active, ['approved_at' => $due->approved_at ?? now()]);
            $this->audit->logSystem(self::LOG_NAME, 'agreement_activated', sprintf('Scheduled compensation agreement %s became active.', $due->reference), $due, $this->safeMeta($due));
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * V1 period alignment: periodic agreements begin and end only on
     * their period boundary in the agreement timezone — no hidden
     * proration formulas exist anywhere.
     */
    private function assertBoundary(CompensationPayBasis $basis, DateTimeInterface $at, string $timezone, string $edge): CarbonImmutable
    {
        $local = CarbonImmutable::instance(Carbon::parse($at))->setTimezone($timezone);

        $aligned = match ($basis) {
            CompensationPayBasis::Hourly => true,
            CompensationPayBasis::Daily => $local->equalTo($local->startOfDay()),
            CompensationPayBasis::Weekly => $local->isMonday() && $local->equalTo($local->startOfDay()),
            CompensationPayBasis::Monthly => $local->day === 1 && $local->equalTo($local->startOfDay()),
        };

        if (! $aligned) {
            throw new CompensationException(sprintf(
                'A %s agreement must %s on a %s boundary (%s). Partial periods are handled through audited adjustments, never proration.',
                $basis->shortLabel(),
                $edge === 'start' ? 'start' : 'end',
                match ($basis) {
                    CompensationPayBasis::Daily => 'day (00:00)',
                    CompensationPayBasis::Weekly => 'week (Monday 00:00)',
                    CompensationPayBasis::Monthly => 'month (1st, 00:00)',
                    default => 'period',
                },
                $timezone,
            ));
        }

        return $local->setTimezone(config('app.timezone'));
    }

    private function assertNoOverlap(InstructorCompensationAgreement $agreement): void
    {
        $overlap = InstructorCompensationAgreement::query()
            ->forInstructor($agreement->instructor_id)
            ->whereKeyNot($agreement->id)
            ->whereIn('status', [CompensationAgreementStatus::Active, CompensationAgreementStatus::Scheduled])
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $agreement->effective_from))
            ->when($agreement->effective_until !== null, fn ($q) => $q->where('effective_from', '<', $agreement->effective_until))
            ->lockForUpdate()
            ->exists();

        if ($overlap) {
            throw new CompensationException('The effective period overlaps another active or scheduled agreement for this instructor.');
        }
    }

    private function validateTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new CompensationException('Unknown timezone.');
        }

        return $timezone;
    }

    private function locked(InstructorCompensationAgreement $agreement): InstructorCompensationAgreement
    {
        return InstructorCompensationAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function transition(InstructorCompensationAgreement $agreement, CompensationAgreementStatus $next, array $extra = []): InstructorCompensationAgreement
    {
        if (! $agreement->status->canTransitionTo($next)) {
            throw new CompensationException(sprintf('An agreement cannot move from %s to %s.', $agreement->status->label(), $next->label()));
        }

        $agreement->forceFill([...$extra, 'status' => $next])->save();

        return $agreement;
    }

    /** Safe audit metadata — never internal reasons, never student pricing. */
    private function safeMeta(InstructorCompensationAgreement $agreement): array
    {
        return [
            'agreement_reference' => $agreement->reference,
            'instructor_id' => $agreement->instructor_id,
            'pay_basis' => $agreement->pay_basis->value,
            'amount_minor' => $agreement->amount_minor,
            'currency' => $agreement->currency_code,
            'effective_from' => $agreement->effective_from?->toIso8601String(),
            'effective_until' => $agreement->effective_until?->toIso8601String(),
        ];
    }

    private function generateReference(): string
    {
        do {
            $reference = 'ICA-'.strtoupper(Str::random(10));
        } while (InstructorCompensationAgreement::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
