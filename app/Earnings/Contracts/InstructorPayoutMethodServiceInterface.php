<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodType;
use App\Models\InstructorPayoutMethod;
use App\Models\User;

interface InstructorPayoutMethodServiceInterface
{
    public function createDraft(User $instructor, PayoutMethodType $type, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): InstructorPayoutMethod;

    /** Draft/rejected only — sensitive details are always re-entered in full. */
    public function updateDraft(InstructorPayoutMethod $method, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): InstructorPayoutMethod;

    public function submitForVerification(InstructorPayoutMethod $method, User $actor): InstructorPayoutMethod;

    public function verify(InstructorPayoutMethod $method, User $admin): InstructorPayoutMethod;

    public function reject(InstructorPayoutMethod $method, User $admin, string $reason): InstructorPayoutMethod;

    /** Verified methods only; transactionally guarantees a single default. */
    public function setDefault(InstructorPayoutMethod $method, User $actor): InstructorPayoutMethod;

    public function disable(InstructorPayoutMethod $method, User $actor, ?string $reason = null): InstructorPayoutMethod;

    /**
     * Permission-gated decrypted read for admin verification. The access
     * itself is audit-logged; the returned values never are.
     *
     * @return array<string, ?string>
     */
    public function viewSensitiveDetails(InstructorPayoutMethod $method, User $admin): array;
}
