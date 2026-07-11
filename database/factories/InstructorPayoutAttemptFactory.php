<?php

namespace Database\Factories;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstructorPayoutAttempt>
 */
class InstructorPayoutAttemptFactory extends Factory
{
    public function definition(): array
    {
        $withdrawal = InstructorWithdrawalRequest::factory()->approved()->create();

        return [
            'reference' => 'PA-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
            'withdrawal_request_id' => $withdrawal->id,
            'instructor_id' => $withdrawal->instructor_id,
            'provider' => 'fake',
            'execution_sequence' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(32)),
            'amount_minor' => $withdrawal->amount_minor,
            'currency_code' => $withdrawal->currency_code,
            'status' => InstructorPayoutAttemptStatus::Created,
            'initiated_by' => User::factory(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstructorPayoutAttemptStatus::Succeeded,
            'provider_payout_id' => 'fake_success_immediate_'.hash('sha256', $attributes['idempotency_key'] ?? Str::random(8)),
            'provider_status' => 'paid',
            'acknowledged_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
