<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Booking\Enums\BookingStatus;
use App\Homework\Exceptions\InvalidHomeworkContextException;
use App\Models\Booking;
use App\Models\StudentLearningPlan;
use App\Models\User;

/**
 * Phase 24J — GAP-021 (SRS §7.19): the single authority on homework
 * educational context. Every homework mutation that sets or changes
 * booking_id / learning_plan_id must pass through validate(), which
 * enforces:
 *
 *  - At least one context: booking_id OR learning_plan_id (SRS §7.19
 *    "Every homework assignment shall reference a Lesson or Learning
 *    Plan"). Both are allowed; neither is rejected.
 *  - Lesson context: the booking must exist, belong to the assignment's
 *    student AND the assigning instructor, and — for newly introduced
 *    links — be Completed (SRS §11.32 / §6.13: instructors assign
 *    homework AFTER lesson completion) and not soft-deleted.
 *  - Plan context: the plan must exist, belong to the student, name the
 *    assigning instructor as primary instructor, and — for newly
 *    introduced links — be in a writable status (not Completed/Archived,
 *    mirroring LearningPlanStatus::isWritable()) and not soft-deleted.
 *  - Both supplied: student and instructor must be consistent across
 *    booking and plan. Bookings carry no learning-plan association and
 *    no authoritative subject field in the current schema, so those two
 *    SRS cross-checks are structurally not applicable and documented here.
 *
 * Links RETAINED unchanged on an update ($…IsNewLink = false) skip only
 * the lifecycle-eligibility checks: historical homework keeps its link
 * to a since-archived plan or historical lesson (SRS §7.19 "Homework
 * history shall remain available"), but ownership and existence are
 * still asserted so a foreign or dangling link can never pass through.
 *
 * Reads are fresh and row-locked; callers must invoke this inside the
 * mutation transaction so validation serializes against concurrent
 * archive/cancel operations.
 */
final class HomeworkContextValidator
{
    /**
     * @return array{0: ?Booking, 1: ?StudentLearningPlan}
     *
     * @throws InvalidHomeworkContextException
     */
    public function validate(
        User $student,
        User $instructor,
        ?string $bookingId,
        ?int $learningPlanId,
        bool $bookingIsNewLink = true,
        bool $planIsNewLink = true,
    ): array {
        if ($bookingId === null && $learningPlanId === null) {
            throw new InvalidHomeworkContextException(
                'Homework must be linked to a lesson or a learning plan.',
            );
        }

        $booking = $bookingId !== null
            ? $this->validatedBooking($bookingId, $student, $instructor, $bookingIsNewLink)
            : null;

        $plan = $learningPlanId !== null
            ? $this->validatedPlan($learningPlanId, $student, $instructor, $planIsNewLink)
            : null;

        if ($booking !== null && $plan !== null && $booking->student_id !== $plan->student_user_id) {
            throw new InvalidHomeworkContextException(
                'The selected lesson and learning plan belong to different students.',
            );
        }

        return [$booking, $plan];
    }

    private function validatedBooking(string $bookingId, User $student, User $instructor, bool $isNewLink): Booking
    {
        $booking = Booking::query()->withTrashed()->whereKey($bookingId)->lockForUpdate()->first();

        if ($booking === null) {
            throw new InvalidHomeworkContextException('The selected lesson could not be found.');
        }

        if ($booking->student_id !== $student->id) {
            throw new InvalidHomeworkContextException('The selected lesson does not belong to this student.');
        }

        if ($booking->instructor_id !== $instructor->id) {
            throw new InvalidHomeworkContextException('You can only assign homework for your own lessons.');
        }

        if ($isNewLink && $booking->trashed()) {
            throw new InvalidHomeworkContextException('The selected lesson is no longer available for homework.');
        }

        if ($isNewLink && $booking->status !== BookingStatus::Completed) {
            throw new InvalidHomeworkContextException('Homework can only be assigned to a completed lesson.');
        }

        return $booking;
    }

    private function validatedPlan(int $learningPlanId, User $student, User $instructor, bool $isNewLink): StudentLearningPlan
    {
        $plan = StudentLearningPlan::query()->withTrashed()->whereKey($learningPlanId)->lockForUpdate()->first();

        if ($plan === null) {
            throw new InvalidHomeworkContextException('The selected learning plan could not be found.');
        }

        if ($plan->student_user_id !== $student->id) {
            throw new InvalidHomeworkContextException('The selected learning plan does not belong to this student.');
        }

        if ($plan->primary_instructor_user_id !== $instructor->id) {
            throw new InvalidHomeworkContextException('You can only assign homework for learning plans you lead.');
        }

        if ($isNewLink && $plan->trashed()) {
            throw new InvalidHomeworkContextException('The selected learning plan is no longer available for homework.');
        }

        if ($isNewLink && ! $plan->status->isWritable()) {
            throw new InvalidHomeworkContextException('Completed or archived learning plans cannot receive new homework.');
        }

        return $plan;
    }
}
