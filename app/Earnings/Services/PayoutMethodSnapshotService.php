<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\PayoutMethodSnapshotServiceInterface;
use App\Models\InstructorPayoutMethod;

/**
 * Builds the immutable payment-destination snapshot stored (encrypted)
 * on a withdrawal request at creation. Captured exactly once — the
 * withdrawal never re-reads the payout method afterwards, so disabling
 * or replacing the method cannot change where an approved request will
 * pay out. schema_version supports future payload migrations.
 */
final class PayoutMethodSnapshotService implements PayoutMethodSnapshotServiceInterface
{
    public const int SCHEMA_VERSION = 1;

    public function capture(InstructorPayoutMethod $method): array
    {
        $details = $method->encrypted_details ?? [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'payout_method_id' => $method->id,
            'payout_method_type' => $method->type->value,
            'country_id' => $method->country_id,
            'currency_code' => $method->currency_code,
            'masked_identifier' => $method->masked_identifier,
            'account_holder_name' => $details['account_holder_name'] ?? null,
            'bank_name' => $details['bank_name'] ?? null,
            'account_number' => $details['account_number'] ?? null,
            'iban' => $details['iban'] ?? null,
            'routing_type' => $details['routing_type'] ?? null,
            'routing_number' => $details['routing_number'] ?? null,
            'swift_bic' => $details['swift_bic'] ?? null,
            'branch_name' => $details['branch_name'] ?? null,
            'account_type' => $details['account_type'] ?? null,
            'beneficiary_address' => $details['beneficiary_address'] ?? null,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
