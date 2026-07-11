<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Models\InstructorPayoutMethod;

interface PayoutMethodSnapshotServiceInterface
{
    /**
     * The immutable payment-destination snapshot captured at withdrawal
     * creation. Built once from the live method, stored encrypted, and
     * never regenerated afterwards.
     *
     * @return array<string, mixed>
     */
    public function capture(InstructorPayoutMethod $method): array;
}
