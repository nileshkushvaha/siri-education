<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Earnings\DTOs\PayoutInitiationResult;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;
use Carbon\CarbonImmutable;

/**
 * The single place a RazorpayX payout status string
 * (queued/pending/rejected/processing/processed/cancelled/reversed/failed)
 * is translated into the internal `InstructorPayoutAttemptStatus`
 * vocabulary. `pending` is deliberately NOT success — RazorpayX's
 * Approval Workflow can hold a payout in `pending` indefinitely before
 * anyone at the business approves it. Only `processed` ever maps to
 * Succeeded. The raw provider description (`failureReason`) is
 * inspected here for classification only — it never becomes the
 * outward-facing `safeReason`, which is always one of the fixed,
 * generic messages below (see PayoutFailureCategory's docblock: "raw
 * provider error text never shown to instructor").
 */
final class RazorpayXStatusMapper
{
    public function toInitiationResult(RazorpayXPayoutResult $result): PayoutInitiationResult
    {
        [$status, $category] = $this->classify($result->status, $result->failureReason);

        return new PayoutInitiationResult(
            attemptStatus: $status,
            providerPayoutId: $result->payoutId,
            providerStatus: $result->status,
            safeReason: $this->safeReason($result->status, $category),
            failureCategory: $category,
            safeMetadata: $this->metadata($result),
        );
    }

    public function toStatusResult(RazorpayXPayoutResult $result): PayoutStatusResult
    {
        [$status, $category] = $this->classify($result->status, $result->failureReason);

        return new PayoutStatusResult(
            attemptStatus: $status,
            providerPayoutId: $result->payoutId,
            providerStatus: $result->status,
            safeReason: $this->safeReason($result->status, $category),
            failureCategory: $category,
            providerOccurredAt: CarbonImmutable::now(),
        );
    }

    /** @return array{0: InstructorPayoutAttemptStatus, 1: ?PayoutFailureCategory} */
    public function classify(string $providerStatus, ?string $failureReason): array
    {
        return match (strtolower($providerStatus)) {
            // Provider has accepted the request but has not started
            // moving funds — `pending` may sit here for a human Approval
            // Workflow decision, so it must never be treated as success.
            'queued', 'pending' => [InstructorPayoutAttemptStatus::Acknowledged, null],
            'processing' => [InstructorPayoutAttemptStatus::Processing, null],
            'processed' => [InstructorPayoutAttemptStatus::Succeeded, null],
            'cancelled' => [InstructorPayoutAttemptStatus::Cancelled, null],
            'reversed' => [InstructorPayoutAttemptStatus::Reversed, PayoutFailureCategory::ReconciliationRequired],
            // Pre-acceptance validation rejection.
            'rejected' => [InstructorPayoutAttemptStatus::Failed, $this->classifyFailureCategory($failureReason) ?? PayoutFailureCategory::ProviderRejected],
            // Post-acceptance failure.
            'failed' => [InstructorPayoutAttemptStatus::Failed, $this->classifyFailureCategory($failureReason) ?? PayoutFailureCategory::ProviderPermanent],
            default => [InstructorPayoutAttemptStatus::Unknown, PayoutFailureCategory::ReconciliationRequired],
        };
    }

    private function classifyFailureCategory(?string $reason): ?PayoutFailureCategory
    {
        $needle = strtolower((string) $reason);

        if ($needle === '') {
            return null;
        }

        return match (true) {
            str_contains($needle, 'ip') && (str_contains($needle, 'allow') || str_contains($needle, 'whitelist')) => PayoutFailureCategory::ProviderIpNotAllowlisted,
            str_contains($needle, 'contact') => PayoutFailureCategory::ProviderContactInvalid,
            str_contains($needle, 'fund_account') || str_contains($needle, 'fund account') || str_contains($needle, 'beneficiary') || str_contains($needle, 'ifsc') => PayoutFailureCategory::ProviderFundAccountInvalid,
            str_contains($needle, 'balance') || str_contains($needle, 'insufficient') => PayoutFailureCategory::InsufficientProviderBalance,
            default => null,
        };
    }

    /** Fixed, generic messages only — never the provider's raw description text. */
    private function safeReason(string $providerStatus, ?PayoutFailureCategory $category): ?string
    {
        return match ($category) {
            PayoutFailureCategory::ProviderIpNotAllowlisted => 'The payout could not be sent because of an outbound IP allowlisting problem with the payout provider.',
            PayoutFailureCategory::ProviderContactInvalid => 'The payout provider rejected the provisioned payee record for this destination.',
            PayoutFailureCategory::ProviderFundAccountInvalid => 'The payout provider rejected this destination bank account.',
            PayoutFailureCategory::InsufficientProviderBalance => 'The payout could not be completed due to insufficient balance in the payout account.',
            PayoutFailureCategory::ProviderRejected => 'The payout provider rejected this payout request.',
            PayoutFailureCategory::ProviderPermanent => 'The payout provider reported this payout as permanently failed.',
            PayoutFailureCategory::ReconciliationRequired => strtolower($providerStatus) === 'reversed'
                ? 'The payout was reversed by the receiving bank after being sent.'
                : 'The payout outcome could not be determined and requires reconciliation.',
            default => null,
        };
    }

    /** @return array<string, mixed> Admin/finance-visible only (stored encrypted, hidden from every serialization) — never surfaced to the instructor. */
    private function metadata(RazorpayXPayoutResult $result): array
    {
        return array_filter([
            'provider_mode' => $result->mode !== '' ? $result->mode : null,
            'provider_utr' => $result->utr,
            'provider_fee_minor' => $result->feesMinor,
            'provider_tax_minor' => $result->taxMinor,
            'provider_status_details' => $result->failureReason,
        ], fn (mixed $v): bool => $v !== null);
    }
}
