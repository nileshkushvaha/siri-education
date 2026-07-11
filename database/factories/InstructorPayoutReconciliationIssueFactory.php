<?php

namespace Database\Factories;

use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\InstructorWithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstructorPayoutReconciliationIssue>
 */
class InstructorPayoutReconciliationIssueFactory extends Factory
{
    public function definition(): array
    {
        $withdrawal = InstructorWithdrawalRequest::factory()->approved()->create();

        return [
            'reference' => 'RI-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
            'withdrawal_request_id' => $withdrawal->id,
            'provider' => 'fake',
            'type' => PayoutReconciliationIssueType::UnknownProviderOutcome,
            'severity' => PayoutReconciliationSeverity::Warning,
            'status' => PayoutReconciliationIssueStatus::Open,
            'amount_minor' => $withdrawal->amount_minor,
            'currency_code' => $withdrawal->currency_code,
            'safe_summary' => 'Factory-generated reconciliation issue.',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ];
    }
}
