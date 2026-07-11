<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use Illuminate\Support\Collection;

/**
 * Writes and releases reservation rows. Only ever called by
 * InstructorWithdrawalService inside its transaction — the earnings
 * passed to reserve() must already be FIFO-ordered and row-locked.
 */
final class InstructorWithdrawalAllocationService implements InstructorWithdrawalAllocationServiceInterface
{
    public function reserve(InstructorWithdrawalRequest $request, Collection $lockedEarnings): Collection
    {
        $remaining = $request->amount_minor;
        $allocations = collect();

        foreach ($lockedEarnings as $earning) {
            if ($remaining <= 0) {
                break;
            }

            if ($earning->currency_code !== $request->currency_code) {
                throw new WithdrawalException('Earnings of a different currency cannot back this withdrawal.');
            }

            if ($earning->instructor_id !== $request->instructor_id) {
                throw new WithdrawalException('Earnings of a different instructor cannot back this withdrawal.');
            }

            // The earning's still-unreserved remainder (partial
            // reservations from other requests reduce it).
            $alreadyHeld = (int) $earning->withdrawalAllocations()
                ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed])
                ->sum('amount_minor');

            $free = $earning->earning_amount_minor - $alreadyHeld;

            if ($free <= 0) {
                continue;
            }

            $slice = min($free, $remaining);

            $allocations->push(InstructorWithdrawalAllocation::query()->create([
                'withdrawal_request_id' => $request->id,
                'instructor_earning_id' => $earning->id,
                'currency_id' => $request->currency_id,
                'currency_code' => $request->currency_code,
                'amount_minor' => $slice,
                'status' => WithdrawalAllocationStatus::Reserved,
                'reserved_at' => now(),
            ]));

            $remaining -= $slice;
        }

        if ($remaining > 0) {
            // The locked recalculation should have caught this — abort the
            // whole transaction rather than under-reserve.
            throw new WithdrawalException('Insufficient available earnings to reserve this withdrawal.');
        }

        return $allocations;
    }

    public function release(InstructorWithdrawalRequest $request): int
    {
        $released = 0;

        $request->allocations()
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->lockForUpdate()
            ->get()
            ->each(function (InstructorWithdrawalAllocation $allocation) use (&$released): void {
                $allocation->fill([
                    'status' => WithdrawalAllocationStatus::Released,
                    'released_at' => now(),
                ])->save();
                $released++;
            });

        return $released;
    }
}
