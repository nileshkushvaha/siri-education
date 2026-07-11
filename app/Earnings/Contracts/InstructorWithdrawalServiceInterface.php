<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;

interface InstructorWithdrawalServiceInterface
{
    /**
     * Create a withdrawal request and reserve earnings, atomically. The
     * amount is fully revalidated server-side under row locks; a repeat
     * with the same idempotency key returns the original request.
     */
    public function requestWithdrawal(
        User $instructor,
        InstructorPayoutMethod $method,
        int $amountMinor,
        ?string $instructorNote = null,
        ?string $idempotencyKey = null,
    ): InstructorWithdrawalRequest;

    public function startReview(InstructorWithdrawalRequest $request, User $admin): InstructorWithdrawalRequest;

    /** Revalidates reservation integrity; reservations are retained. */
    public function approve(InstructorWithdrawalRequest $request, User $admin): InstructorWithdrawalRequest;

    /** Releases every reservation in the same transaction. */
    public function reject(InstructorWithdrawalRequest $request, User $admin, string $reason): InstructorWithdrawalRequest;

    /** Releases every reservation in the same transaction. */
    public function cancelByInstructor(InstructorWithdrawalRequest $request, User $instructor): InstructorWithdrawalRequest;

    /** Releases every reservation in the same transaction. */
    public function cancelByAdmin(InstructorWithdrawalRequest $request, User $admin, ?string $reason = null): InstructorWithdrawalRequest;
}
