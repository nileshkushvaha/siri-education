<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Models\InstructorEarning;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use Illuminate\Support\Collection;

interface InstructorWithdrawalAllocationServiceInterface
{
    /**
     * Reserve the request amount against the instructor's eligible
     * earnings, FIFO, splitting the last earning when needed. Must be
     * called inside the withdrawal-creation transaction with the
     * earning rows already locked.
     *
     * @param  Collection<int, InstructorEarning>  $lockedEarnings  FIFO-ordered, lockForUpdate() rows
     * @return Collection<int, InstructorWithdrawalAllocation>
     */
    public function reserve(InstructorWithdrawalRequest $request, Collection $lockedEarnings): Collection;

    /** Flip every live reservation of the request to released. Same-transaction only. */
    public function release(InstructorWithdrawalRequest $request): int;
}
