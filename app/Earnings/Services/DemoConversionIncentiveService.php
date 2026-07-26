<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Models\DemoConversionIncentiveAward;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\Instructor\DemoConversionIncentiveEarnedNotification;
use App\Services\AuditTrailService;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Settings\DemoConversionIncentiveSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * GAP-008 requirement #4 — the ONE authoritative service for the demo-
 * to-paid conversion incentive: resolves the qualifying demo,
 * validates the full conversion eligibility chain, creates the award +
 * its earning (through InstructorEarningService, never a direct
 * ledger write), guarantees database-backed idempotency and
 * concurrency safety, freezes an immutable rule snapshot, and audits +
 * notifies. Never called from a controller/Filament action directly —
 * only from CheckDemoConversionIncentiveOnLessonCompleted.
 */
final class DemoConversionIncentiveService
{
    private const string LOG_NAME = 'demo_conversion_incentive';

    public function __construct(
        private readonly DemoConversionIncentiveEligibilityResolver $eligibility,
        private readonly InstructorEarningServiceInterface $earnings,
        private readonly DemoConversionIncentiveSettings $settings,
        private readonly AuditTrailService $audit,
        private readonly NotificationIdempotencyGuard $notifications,
    ) {}

    public function evaluate(Lesson $paidLesson): ?DemoConversionIncentiveAward
    {
        $existing = DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $award = DB::transaction(function () use ($paidLesson): ?DemoConversionIncentiveAward {
                // Canonical lock order (matches InstructorEarningService::createSettlementBatch()):
                // lock the instructor's user row first, so two paid
                // lessons for the same pair completing concurrently
                // can never both pass the max-awards-per-pair count
                // check before either commits.
                User::query()->whereKey($paidLesson->instructor_id)->lockForUpdate()->first();

                $result = $this->eligibility->evaluate($paidLesson->fresh());

                if (! $result->eligible) {
                    $this->audit->logSystem(
                        self::LOG_NAME,
                        'award_skipped',
                        sprintf('No demo-conversion incentive for lesson %s: %s', $paidLesson->id, $result->reason),
                        $paidLesson,
                    );

                    return null;
                }

                $demoLesson = $result->demoLesson;

                return DemoConversionIncentiveAward::query()->create([
                    'demo_booking_id' => $demoLesson->booking_id,
                    'demo_lesson_id' => $demoLesson->id,
                    'paid_booking_id' => $paidLesson->booking_id,
                    'paid_lesson_id' => $paidLesson->id,
                    'instructor_id' => $paidLesson->instructor_id,
                    'student_id' => $paidLesson->student_id,
                    'amount_minor' => $this->settings->bonus_amount_minor,
                    'currency_code' => $this->settings->bonus_currency_code,
                    'rule_snapshot' => $this->ruleSnapshot(),
                    'idempotency_key' => self::idempotencyKeyFor($paidLesson),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker created this exact paid lesson's award
            // between our fast-path check and the insert — the
            // idempotent replay, never a failure.
            return DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->first();
        }

        if ($award === null) {
            return null;
        }

        $this->createEarningAndNotify($award, $paidLesson);

        return $award->fresh();
    }

    private function createEarningAndNotify(DemoConversionIncentiveAward $award, Lesson $paidLesson): void
    {
        $demoLesson = $award->demoLesson()->firstOrFail();

        $earning = $this->earnings->createDemoConversionIncentive($award, $paidLesson, $demoLesson);

        if ($earning !== null && $award->instructor_earning_id === null) {
            $award->fill(['instructor_earning_id' => $earning->id])->save();
        }

        $this->audit->logSystem(
            self::LOG_NAME,
            'award_created',
            sprintf('Demo-conversion incentive award %s created: %d %s (minor units) for instructor %d.', $award->id, $award->amount_minor, $award->currency_code, $award->instructor_id),
            $award,
        );

        $this->notify($award);
    }

    private function notify(DemoConversionIncentiveAward $award): void
    {
        $instructor = $award->instructor()->first();

        if ($instructor === null) {
            return;
        }

        $this->notifications->once(
            sprintf('demo_conversion_incentive_earned:%s', $award->id),
            DemoConversionIncentiveEarnedNotification::class,
            fn () => $instructor->notify(new DemoConversionIncentiveEarnedNotification($award->id, $award->amount_minor, $award->currency_code)),
        );
    }

    /** @return array<string, mixed> */
    private function ruleSnapshot(): array
    {
        return [
            'enabled' => $this->settings->enabled,
            'conversion_window_days' => $this->settings->conversion_window_days,
            'min_completed_paid_lessons' => $this->settings->min_completed_paid_lessons,
            'bonus_amount_minor' => $this->settings->bonus_amount_minor,
            'bonus_currency_code' => $this->settings->bonus_currency_code,
            'max_awards_per_pair' => $this->settings->max_awards_per_pair,
            'applicable_country_ids' => $this->settings->applicable_country_ids,
            'applicable_subject_ids' => $this->settings->applicable_subject_ids,
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    public static function idempotencyKeyFor(Lesson $paidLesson): string
    {
        return 'demo_conversion:'.$paidLesson->id;
    }
}
