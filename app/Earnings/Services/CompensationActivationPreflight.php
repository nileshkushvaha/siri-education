<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Enums\InstructorStatus;
use App\Lessons\Enums\LessonStatus;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationException;
use App\Models\Lesson;
use App\Models\User;
use App\Settings\InstructorEarningSettings;

/**
 * Server-side preflight for flipping earnings_enabled ON. Enabling
 * earnings with a misconfigured compensation setup floods the exception
 * queue and blocks instructor pay — so activation is refused until
 * every check passes, and each failure names the exact instructors
 * affected. Purely read-only; enforcement lives in the settings page
 * save path (the only admin surface that can enable the switch).
 */
final class CompensationActivationPreflight
{
    public function __construct(
        private readonly InstructorEarningSettings $settings,
    ) {}

    /**
     * @return list<array{check: string, message: string, subjects: list<string>}> empty = safe to enable
     */
    public function failures(): array
    {
        $failures = [];

        // 1. Every active payable instructor needs a live agreement window.
        $missing = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', [InstructorStatus::Approved, InstructorStatus::Active]))
            ->whereDoesntHave('compensationAgreements', fn ($q) => $q
                ->where('status', CompensationAgreementStatus::Active)
                ->where('effective_from', '<=', now())
                ->where(fn ($w) => $w->whereNull('effective_until')->orWhere('effective_until', '>', now())))
            ->pluck('name')
            ->all();

        if ($missing !== []) {
            $failures[] = [
                'check' => 'missing_agreements',
                'message' => 'Active payable instructors without a valid active compensation agreement.',
                'subjects' => $missing,
            ];
        }

        // 2. No overlapping active/scheduled agreement windows.
        $overlapping = InstructorCompensationAgreement::query()
            ->whereIn('status', [CompensationAgreementStatus::Active, CompensationAgreementStatus::Scheduled])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('instructor_compensation_agreements as other')
                    ->whereColumn('other.instructor_id', 'instructor_compensation_agreements.instructor_id')
                    ->whereColumn('other.id', '!=', 'instructor_compensation_agreements.id')
                    ->whereIn('other.status', ['active', 'scheduled'])
                    ->whereRaw('other.effective_from < COALESCE(instructor_compensation_agreements.effective_until, ?)', ['9999-01-01'])
                    ->whereRaw('COALESCE(other.effective_until, ?) > instructor_compensation_agreements.effective_from', ['9999-01-01']);
            })
            ->with('instructor:id,name')
            ->get()
            ->map(fn (InstructorCompensationAgreement $a): string => sprintf('%s (%s)', $a->instructor?->name, $a->reference))
            ->unique()
            ->values()
            ->all();

        if ($overlapping !== []) {
            $failures[] = [
                'check' => 'overlapping_agreements',
                'message' => 'Agreements whose effective periods overlap.',
                'subjects' => $overlapping,
            ];
        }

        // 3. Agreement currencies must be active.
        $activeCurrencies = Currency::query()->active()->pluck('code')->all();
        $badCurrency = InstructorCompensationAgreement::query()
            ->where('status', CompensationAgreementStatus::Active)
            ->whereNotIn('currency_code', $activeCurrencies)
            ->with('instructor:id,name')
            ->get()
            ->map(fn (InstructorCompensationAgreement $a): string => sprintf('%s (%s, %s)', $a->instructor?->name, $a->reference, $a->currency_code))
            ->all();

        if ($badCurrency !== []) {
            $failures[] = [
                'check' => 'inactive_currency',
                'message' => 'Active agreements using an inactive currency.',
                'subjects' => $badCurrency,
            ];
        }

        // 4. Hourly amounts must be positive (defense in depth over the
        // unsigned column + service validation).
        $badAmount = InstructorCompensationAgreement::query()
            ->where('status', CompensationAgreementStatus::Active)
            ->where('amount_minor', '<=', 0)
            ->with('instructor:id,name')
            ->get()
            ->map(fn (InstructorCompensationAgreement $a): string => sprintf('%s (%s)', $a->instructor?->name, $a->reference))
            ->all();

        if ($badAmount !== []) {
            $failures[] = [
                'check' => 'invalid_amount',
                'message' => 'Active agreements with a non-positive amount.',
                'subjects' => $badAmount,
            ];
        }

        // 5. Upcoming scheduled lessons must have supported durations
        // (1–480 minutes) or they will land in the exception queue.
        $badLessons = Lesson::query()
            ->where('status', LessonStatus::Scheduled)
            ->where('starts_at', '>=', now())
            ->whereRaw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at) NOT BETWEEN 1 AND 480')
            ->count();

        if ($badLessons > 0) {
            $failures[] = [
                'check' => 'unsupported_durations',
                'message' => sprintf('%d upcoming scheduled lesson(s) have unsupported durations (must be 1–480 minutes).', $badLessons),
                'subjects' => [],
            ];
        }

        // 6. No unresolved compensation configuration failures.
        $openExceptions = InstructorCompensationException::query()
            ->open()
            ->with('instructor:id,name')
            ->get()
            ->map(fn (InstructorCompensationException $e): string => sprintf('%s (%s)', $e->instructor?->name, $e->category->value))
            ->unique()
            ->values()
            ->all();

        if ($openExceptions !== []) {
            $failures[] = [
                'check' => 'open_exceptions',
                'message' => 'Unresolved compensation exceptions must be cleared first.',
                'subjects' => $openExceptions,
            ];
        }

        // 7. Periodic agreements require their own explicit rollout gate.
        if (! $this->settings->periodic_compensation_enabled) {
            $periodic = InstructorCompensationAgreement::query()
                ->whereIn('status', [CompensationAgreementStatus::Active, CompensationAgreementStatus::Scheduled])
                ->where('pay_basis', '!=', CompensationPayBasis::Hourly)
                ->with('instructor:id,name')
                ->get()
                ->map(fn (InstructorCompensationAgreement $a): string => sprintf('%s (%s, %s)', $a->instructor?->name, $a->reference, $a->pay_basis->value))
                ->all();

            if ($periodic !== []) {
                $failures[] = [
                    'check' => 'periodic_not_enabled',
                    'message' => 'Daily/weekly/monthly agreements exist but periodic compensation is not enabled.',
                    'subjects' => $periodic,
                ];
            }
        }

        return $failures;
    }
}
