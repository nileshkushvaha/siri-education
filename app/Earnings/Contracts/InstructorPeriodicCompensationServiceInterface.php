<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

interface InstructorPeriodicCompensationServiceInterface
{
    /**
     * Accrue every closed, un-accrued period of every active (or
     * promotable) daily/weekly/monthly agreement: one immutable
     * compensation-period record + exactly one instructor earning per
     * period, idempotent across retries, gated by earnings_enabled.
     * Future incomplete periods are never accrued.
     *
     * @return int number of periods accrued
     */
    public function accrueClosedPeriods(): int;
}
