<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Types\FreeDemoType;
use App\Country\Services\CountryResolver;
use App\Earnings\DTOs\DemoConversionIncentiveEligibilityResult;
use App\Lessons\Enums\LessonStatus;
use App\Models\DemoConversionIncentiveAward;
use App\Models\Lesson;
use App\Settings\DemoConversionIncentiveSettings;

/**
 * The complete eligibility chain for a single completed paid lesson,
 * evaluated against the currently configured
 * rule (rules are a singleton, not effective-dated, so "rule active at
 * the relevant time" means "enabled right now, at evaluation time").
 * Every branch returns a stable reason code; nothing here writes
 * anything.
 */
final class DemoConversionIncentiveEligibilityResolver
{
    public function __construct(
        private readonly DemoConversionIncentiveSettings $settings,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function evaluate(Lesson $paidLesson): DemoConversionIncentiveEligibilityResult
    {
        if (! $this->settings->enabled) {
            return DemoConversionIncentiveEligibilityResult::ineligible('rule_disabled');
        }

        if ($paidLesson->status !== LessonStatus::Completed || $paidLesson->completed_at === null) {
            return DemoConversionIncentiveEligibilityResult::ineligible('paid_lesson_not_completed');
        }

        if ($paidLesson->instructor_id === null || $paidLesson->student_id === null) {
            return DemoConversionIncentiveEligibilityResult::ineligible('participant_missing');
        }

        $paidBooking = $paidLesson->booking;

        if ($paidBooking === null) {
            return DemoConversionIncentiveEligibilityResult::ineligible('paid_booking_missing');
        }

        if (! in_array($paidBooking->status, [BookingStatus::Confirmed, BookingStatus::Completed], strict: true)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('paid_booking_not_confirmed');
        }

        if ($paidBooking->payment_status !== BookingPaymentStatus::Paid) {
            return DemoConversionIncentiveEligibilityResult::ineligible('paid_booking_not_financially_settled');
        }

        if ($paidBooking->type?->is_paid !== true) {
            return DemoConversionIncentiveEligibilityResult::ineligible('not_a_paid_lesson_type');
        }

        $demoLesson = $this->qualifyingDemo($paidLesson);

        if ($demoLesson === null) {
            return DemoConversionIncentiveEligibilityResult::ineligible('no_completed_demo_for_pair');
        }

        if ($paidLesson->completed_at->lessThan($demoLesson->completed_at)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('paid_lesson_precedes_demo');
        }

        $windowEnd = $demoLesson->completed_at->addDays(max(0, $this->settings->conversion_window_days));

        if ($paidLesson->completed_at->greaterThan($windowEnd)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('outside_conversion_window');
        }

        if ($this->completedPaidLessonCount($paidLesson) < max(1, $this->settings->min_completed_paid_lessons)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('minimum_paid_lessons_not_met');
        }

        $countryIds = $this->settings->applicable_country_ids;

        if ($countryIds !== []) {
            $instructorCountryId = $this->countryResolver->forInstructor($paidLesson->instructor)?->id;

            if ($instructorCountryId === null || ! in_array($instructorCountryId, $countryIds, true)) {
                return DemoConversionIncentiveEligibilityResult::ineligible('country_not_applicable');
            }
        }

        $subjectIds = $this->settings->applicable_subject_ids;

        if ($subjectIds !== [] && ! in_array($paidLesson->subject_id, $subjectIds, true)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('subject_not_applicable');
        }

        $existingAwards = DemoConversionIncentiveAward::query()
            ->where('instructor_id', $paidLesson->instructor_id)
            ->where('student_id', $paidLesson->student_id)
            ->count();

        if ($existingAwards >= max(1, $this->settings->max_awards_per_pair)) {
            return DemoConversionIncentiveEligibilityResult::ineligible('award_limit_reached');
        }

        return DemoConversionIncentiveEligibilityResult::eligible($demoLesson);
    }

    /** The (earliest) completed demo lesson between the exact same student/instructor pair — at most one demo per pair is possible today (OneFreeDemoPerInstructorRule), but earliest is the defensive, deterministic choice regardless. */
    private function qualifyingDemo(Lesson $paidLesson): ?Lesson
    {
        return Lesson::query()
            ->where('student_id', $paidLesson->student_id)
            ->where('instructor_id', $paidLesson->instructor_id)
            ->where('status', LessonStatus::Completed)
            ->whereNotNull('completed_at')
            ->whereHas('booking', fn ($query) => $query->whereHas('type', fn ($type) => $type->where('key', FreeDemoType::KEY)))
            ->orderBy('completed_at')
            ->first();
    }

    /** Every completed paid lesson for this pair, including the one being evaluated. */
    private function completedPaidLessonCount(Lesson $paidLesson): int
    {
        return Lesson::query()
            ->where('student_id', $paidLesson->student_id)
            ->where('instructor_id', $paidLesson->instructor_id)
            ->where('status', LessonStatus::Completed)
            ->whereNotNull('completed_at')
            ->whereHas('booking', fn ($query) => $query->whereHas('type', fn ($type) => $type->where('is_paid', true)))
            ->count();
    }
}
