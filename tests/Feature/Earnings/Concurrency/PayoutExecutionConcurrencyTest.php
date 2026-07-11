<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutProviderEvent;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\ManagesFinancialSettings;

/**
 * Real multi-process races for the Phase 16A execution/reconciliation
 * layer, on top of the same tests/Concurrency/run-op.php harness proven
 * in Phase 15.1/14.4. QUEUE_CONNECTION=sync in .env.testing means
 * queueExecution() runs InitiateInstructorPayout inline within the
 * worker process, so "queue" and "execute" happen atomically from the
 * test's point of view — exactly what the "concurrent execution" and
 * "queue retry vs manual retry" scenarios need to race against.
 */
class PayoutExecutionConcurrencyTest extends ConcurrencyTestCase
{
    use ManagesFinancialSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'withdrawal_review_required' => false,
            'payout_execution_enabled' => true,
            'payout_provider' => 'fake',
            'payout_maker_checker_enabled' => true,
            'payout_auto_retry_enabled' => false,
        ]);
    }

    public function test_concurrent_execution_of_the_same_withdrawal_creates_exactly_one_attempt(): void
    {
        [$withdrawal, , $approver] = $this->approvedWithdrawal(50000);
        $executorA = $this->makeStaffUser();
        $executorB = $this->makeStaffUser();

        // Both executors are distinct from the approver — maker-checker
        // does not itself decide the race; the withdrawal row lock does.
        $results = $this->race([
            ['queue-execution', ['withdrawal_id' => $withdrawal->id, 'actor_id' => $executorA->id]],
            ['queue-execution', ['withdrawal_id' => $withdrawal->id, 'actor_id' => $executorB->id]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame(PayoutExecutionException::class, $failed[0]['exception']);

        // One logical attempt, one execution sequence, one idempotency key.
        $attempts = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->get();
        $this->assertCount(1, $attempts, 'Exactly one payout attempt must exist for this withdrawal.');
        $this->assertSame(1, $attempts->first()->execution_sequence);
        $this->assertNotNull($attempts->first()->provider_payout_id, 'One provider payout must have been created.');

        // One withdrawal transition — the sync-queue execution resolves
        // to paid (default fake scenario = success_immediate).
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);
    }

    public function test_concurrent_duplicate_provider_events_apply_the_financial_effect_once(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(40000);
        $executor = $this->makeStaffUser();

        app(InstructorPayoutExecutionServiceInterface::class)
            ->queueExecution($withdrawal, $executor);

        $attempt = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->firstOrFail();
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status, 'Precondition: the attempt must already be succeeded before the duplicate-event race.');

        $eventId = (string) Str::uuid();

        $results = $this->race([
            ['apply-payout-event', [
                'provider' => 'fake', 'provider_event_id' => $eventId, 'event_type' => 'payout.succeeded',
                'provider_payout_id' => $attempt->provider_payout_id, 'status' => 'succeeded',
                'amount_minor' => $attempt->amount_minor, 'currency_code' => $attempt->currency_code,
            ]],
            ['apply-payout-event', [
                'provider' => 'fake', 'provider_event_id' => $eventId, 'event_type' => 'payout.succeeded',
                'provider_payout_id' => $attempt->provider_payout_id, 'status' => 'succeeded',
                'amount_minor' => $attempt->amount_minor, 'currency_code' => $attempt->currency_code,
            ]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        // Exactly one event row is the "original"; any duplicate is a
        // distinct, clearly marked row, and the (provider, event_id)
        // uniqueness backstop was never violated (no uncaught DB error).
        $originals = InstructorPayoutProviderEvent::query()
            ->where('provider', 'fake')->where('provider_event_id', $eventId)->get();
        $this->assertCount(1, $originals);

        $ignored = InstructorPayoutProviderEvent::query()
            ->where('provider', 'fake')->where('provider_event_id', 'like', $eventId.':dup:%')->get();
        $this->assertCount(1, $ignored, 'The racing duplicate must be recorded, not silently dropped or crashed.');
        $this->assertSame('ignored', $ignored->first()->processing_status);

        // One paid transition, one allocation consumption — never doubled.
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);
        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Consumed)
            ->sum('amount_minor'));
    }

    public function test_settlement_excludes_an_earning_concurrently_being_paid_via_withdrawal(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 60000);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal(
            $instructor, $method, 60000, null, (string) Str::uuid(),
        );
        $approver = $this->makeStaffUser();
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, $approver);
        $executor = $this->makeStaffUser();

        $results = $this->race([
            ['queue-execution', ['withdrawal_id' => $withdrawal->id, 'actor_id' => $executor->id]],
            ['settle', ['instructor_id' => $instructor->id, 'currency_code' => 'INR']],
        ]);

        // Settlement must never win a race against a withdrawal that has
        // (even momentarily) reserved/consumed this earning — invariant
        // #17: settlement and payout execution never consume the same
        // earning amount.
        $settleResult = $results[1];

        if ($settleResult['ok']) {
            $batch = InstructorSettlementBatch::query()->findOrFail($settleResult['result']['batch_id']);
            $this->assertFalse(
                $batch->earnings()->where('instructor_earnings.id', $earning->id)->exists(),
                'A settlement batch must never include an earning that a withdrawal has reserved or consumed.',
            );
        }

        // The earning's committed amount (reserved+consumed, minus any
        // settlement double-count) never exceeds its own value.
        $earning->refresh();
        $withdrawalCommitted = (int) InstructorWithdrawalAllocation::query()
            ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed])
            ->where('instructor_earning_id', $earning->id)
            ->sum('amount_minor');
        $settledElsewhere = $earning->settlement_batch_id !== null ? $earning->earning_amount_minor : 0;

        $this->assertLessThanOrEqual(
            $earning->earning_amount_minor,
            $withdrawalCommitted + $settledElsewhere,
            'The same earning amount must never be both withdrawal-committed and settlement-assigned.',
        );
    }

    public function test_concurrent_manual_retries_never_duplicate_an_attempt(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(35000);
        $executor = $this->makeStaffUser();

        // Force a permanent, pre-acceptance-style failure is hard to
        // stage deterministically here; instead exercise the safer,
        // universally-true invariant: two concurrent manual retries
        // against an already-paid (execution-complete) withdrawal must
        // both fail cleanly and create no further attempt — retry is
        // never a backdoor around "nothing left to retry".
        app(InstructorPayoutExecutionServiceInterface::class)
            ->queueExecution($withdrawal, $executor);
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);

        $retryA = $this->makeStaffUser();
        $retryB = $this->makeStaffUser();

        $results = $this->race([
            ['retry-payout', ['withdrawal_id' => $withdrawal->id, 'actor_id' => $retryA->id, 'reason' => 'Race A']],
            ['retry-payout', ['withdrawal_id' => $withdrawal->id, 'actor_id' => $retryB->id, 'reason' => 'Race B']],
        ]);

        foreach ($results as $result) {
            $this->assertFalse($result['ok'], 'A paid withdrawal has nothing to retry — both attempts must fail.');
            $this->assertSame(PayoutExecutionException::class, $result['exception']);
        }

        $this->assertCount(1, InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->get());
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return array{0: InstructorWithdrawalRequest, 1: User, 2: User} */
    private function approvedWithdrawal(int $amountMinor): array
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, $amountMinor);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal(
            $instructor, $method, $amountMinor, null, (string) Str::uuid(),
        );

        $approver = $this->makeStaffUser();
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, $approver);

        return [$withdrawal, $instructor, $approver];
    }

    private function makeStaffUser(): User
    {
        return User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }
}
