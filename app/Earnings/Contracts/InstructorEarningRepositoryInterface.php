<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface InstructorEarningRepositoryInterface
{
    public function findForLesson(Lesson $lesson): ?InstructorEarning;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): InstructorEarning;

    /**
     * Persist a guarded status change plus extra attribute updates.
     * Guarding (canTransitionTo) is the action's job — this only writes.
     *
     * @param  array<string, mixed>  $extra
     */
    public function transitionStatus(InstructorEarning $earning, InstructorEarningStatus $status, array $extra = []): InstructorEarning;

    /**
     * Pending-hold earnings whose hold has lapsed — the release sweep's
     * work queue.
     *
     * @return LazyCollection<int, InstructorEarning>
     */
    public function dueForRelease(CarbonInterface $now): LazyCollection;

    /**
     * Releasable, unassigned, reservation-free earnings for one
     * instructor + currency, optionally bounded by released_at date
     * range. Pass forUpdate=true only inside a transaction — the final
     * settlement eligibility decision must be made on locked rows,
     * never on a stale unlocked read.
     *
     * @return Collection<int, InstructorEarning>
     */
    public function settleable(int $instructorId, string $currencyCode, ?CarbonInterface $from = null, ?CarbonInterface $until = null, bool $forUpdate = false): Collection;
}
