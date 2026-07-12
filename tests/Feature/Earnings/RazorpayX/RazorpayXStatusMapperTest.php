<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;
use App\Earnings\Providers\RazorpayX\RazorpayXStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RazorpayXStatusMapperTest extends TestCase
{
    private RazorpayXStatusMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new RazorpayXStatusMapper;
    }

    /** @return array<string, array{0: string, 1: InstructorPayoutAttemptStatus, 2: ?PayoutFailureCategory}> */
    public static function statusProvider(): array
    {
        return [
            'queued is acknowledged, not success' => ['queued', InstructorPayoutAttemptStatus::Acknowledged, null],
            'pending is acknowledged, not success (may be an Approval Workflow hold)' => ['pending', InstructorPayoutAttemptStatus::Acknowledged, null],
            'processing maps to processing' => ['processing', InstructorPayoutAttemptStatus::Processing, null],
            'processed is the only success mapping' => ['processed', InstructorPayoutAttemptStatus::Succeeded, null],
            'cancelled maps to cancelled' => ['cancelled', InstructorPayoutAttemptStatus::Cancelled, null],
            'reversed maps to reversed and requires reconciliation' => ['reversed', InstructorPayoutAttemptStatus::Reversed, PayoutFailureCategory::ReconciliationRequired],
            'unrecognized status maps to unknown' => ['some_future_status', InstructorPayoutAttemptStatus::Unknown, PayoutFailureCategory::ReconciliationRequired],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_status_classification(string $providerStatus, InstructorPayoutAttemptStatus $expectedStatus, ?PayoutFailureCategory $expectedCategory): void
    {
        [$status, $category] = $this->mapper->classify($providerStatus, null);

        $this->assertSame($expectedStatus, $status);
        $this->assertSame($expectedCategory, $category);
    }

    public function test_rejected_defaults_to_provider_rejected_without_a_recognizable_reason(): void
    {
        [$status, $category] = $this->mapper->classify('rejected', 'Some unrelated description');

        $this->assertSame(InstructorPayoutAttemptStatus::Failed, $status);
        $this->assertSame(PayoutFailureCategory::ProviderRejected, $category);
    }

    public function test_failed_defaults_to_provider_permanent_without_a_recognizable_reason(): void
    {
        [$status, $category] = $this->mapper->classify('failed', 'Some unrelated description');

        $this->assertSame(InstructorPayoutAttemptStatus::Failed, $status);
        $this->assertSame(PayoutFailureCategory::ProviderPermanent, $category);
    }

    public function test_ip_allowlisting_reason_is_classified(): void
    {
        [, $category] = $this->mapper->classify('rejected', 'Source IP not in allowlist');

        $this->assertSame(PayoutFailureCategory::ProviderIpNotAllowlisted, $category);
    }

    public function test_contact_reason_is_classified(): void
    {
        [, $category] = $this->mapper->classify('rejected', 'The contact could not be found');

        $this->assertSame(PayoutFailureCategory::ProviderContactInvalid, $category);
    }

    public function test_fund_account_reason_is_classified(): void
    {
        [, $category] = $this->mapper->classify('failed', 'The fund_account is invalid');

        $this->assertSame(PayoutFailureCategory::ProviderFundAccountInvalid, $category);
    }

    public function test_insufficient_balance_reason_is_classified(): void
    {
        [, $category] = $this->mapper->classify('failed', 'Insufficient balance in source account');

        $this->assertSame(PayoutFailureCategory::InsufficientProviderBalance, $category);
    }

    /** §22: raw provider error text must never reach the outward-facing safeReason. */
    public function test_safe_reason_never_contains_the_raw_provider_description(): void
    {
        $rawDescription = 'RZPX_ERR_9182: beneficiary account frozen per internal risk flag 44821';

        $result = new RazorpayXPayoutResult(
            payoutId: 'pout_test123',
            status: 'failed',
            utr: null,
            feesMinor: null,
            taxMinor: null,
            mode: 'IMPS',
            referenceId: 'attempt-ref',
            failureReason: $rawDescription,
        );

        $initiation = $this->mapper->toInitiationResult($result);
        $status = $this->mapper->toStatusResult($result);

        $this->assertStringNotContainsString($rawDescription, (string) $initiation->safeReason);
        $this->assertStringNotContainsString($rawDescription, (string) $status->safeReason);
        $this->assertStringNotContainsString('RZPX_ERR_9182', (string) $initiation->safeReason);
    }

    /** The raw description is still preserved for finance/admin visibility — inside safeMetadata (encrypted, hidden), never the instructor-facing field. */
    public function test_raw_provider_description_is_preserved_in_metadata_for_finance_visibility(): void
    {
        $rawDescription = 'RZPX_ERR_9182: beneficiary account frozen';

        $result = new RazorpayXPayoutResult(
            payoutId: 'pout_test123',
            status: 'failed',
            utr: 'UTR12345',
            feesMinor: 826,
            taxMinor: 126,
            mode: 'IMPS',
            referenceId: 'attempt-ref',
            failureReason: $rawDescription,
        );

        $initiation = $this->mapper->toInitiationResult($result);

        $this->assertSame($rawDescription, $initiation->safeMetadata['provider_status_details']);
        $this->assertSame('UTR12345', $initiation->safeMetadata['provider_utr']);
        $this->assertSame(826, $initiation->safeMetadata['provider_fee_minor']);
        $this->assertSame(126, $initiation->safeMetadata['provider_tax_minor']);
    }
}
