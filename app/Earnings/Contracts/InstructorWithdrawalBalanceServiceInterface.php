<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\WithdrawalBalance;
use App\Models\User;

interface InstructorWithdrawalBalanceServiceInterface
{
    /**
     * The instructor's available-withdrawal balance for one currency,
     * derived from canonical records (releasable unassigned earnings
     * minus live reservations). Pass forUpdate=true only inside a
     * transaction — it locks the underlying earning rows.
     */
    public function calculate(User $instructor, string $currencyCode, bool $forUpdate = false): WithdrawalBalance;

    /**
     * Currency codes in which the instructor currently has any eligible
     * earnings.
     *
     * @return list<string>
     */
    public function currenciesWithBalance(User $instructor): array;
}
