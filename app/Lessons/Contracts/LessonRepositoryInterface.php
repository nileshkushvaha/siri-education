<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

interface LessonRepositoryInterface
{
    public function findForBooking(Booking $booking): ?Lesson;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Lesson;

    /** Refetch the lesson with a row lock — call only inside a transaction. */
    public function lockForUpdate(Lesson $lesson): Lesson;

    /**
     * Persist a guarded status change plus any extra attribute updates.
     * Guarding (canTransitionTo) is the action's job — this only writes.
     *
     * @param  array<string, mixed>  $extra
     */
    public function transitionStatus(Lesson $lesson, LessonStatus $status, array $extra = []): Lesson;

    /**
     * Open lessons (scheduled/live) whose end time is at or before the
     * given cutoff — the auto-completion sweep's work queue.
     *
     * @return LazyCollection<int, Lesson>
     */
    public function openEndedBefore(CarbonInterface $cutoff): LazyCollection;
}
