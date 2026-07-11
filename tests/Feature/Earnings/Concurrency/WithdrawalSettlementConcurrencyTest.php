<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\WithdrawalException;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Phase 14 / Phase 15 boundary under real multi-process contention:
 * settlement drafting and withdrawal reservation compete for the same
 * earnings on separate MySQL connections. Both paths take the
 * instructor-row lock first and re-derive eligibility on locked rows,
 * so an earning can only ever leave the pool down ONE path.
 */
class WithdrawalSettlementConcurrencyTest extends ConcurrencyTestCase
{
    public function test_an_earning_is_consumed_by_exactly_one_financial_path(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 50000);

        $results = $this->race([
            ['withdraw', ['instructor_id' => $instructor->id, 'method_id' => $method->id, 'amount_minor' => 50000, 'idempotency_key' => (string) Str::uuid()]],
            ['settle', ['instructor_id' => $instructor->id, 'currency_code' => 'INR']],
        ]);

        [$withdraw, $settle] = $results;

        // Exactly one path wins the earning; the loser gets a safe domain
        // exception, never a partial write.
        $this->assertTrue($withdraw['ok'] xor $settle['ok'], json_encode($results));

        $earning->refresh();

        $reserved = (int) InstructorWithdrawalAllocation::query()
            ->where('instructor_earning_id', $earning->id)
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor');

        if ($withdraw['ok']) {
            $this->assertSame(EarningException::class, $settle['exception']);
            $this->assertSame(50000, $reserved);
            $this->assertNull($earning->settlement_batch_id);
            // The losing settlement left no partial batch behind.
            $this->assertSame(0, InstructorSettlementBatch::query()->forInstructor($instructor->id)->count());
        } else {
            $this->assertSame(WithdrawalException::class, $withdraw['exception']);
            $this->assertSame(0, $reserved);
            $this->assertNotNull($earning->settlement_batch_id);
            // The losing withdrawal left no partial request or allocation.
            $this->assertSame(0, InstructorWithdrawalRequest::query()->forInstructor($instructor->id)->count());
            $this->assertSame(0, InstructorWithdrawalAllocation::query()->where('instructor_earning_id', $earning->id)->count());
            // …and dispatched no notification for the rolled-back attempt.
            $this->assertSame(0, DB::table('notifications')
                ->where('notifiable_id', $instructor->id)
                ->where('type', 'like', '%WithdrawalStatus%')
                ->count());
        }

        // The core invariant either way: never simultaneously reserved
        // and settlement-assigned.
        $this->assertFalse($reserved > 0 && $earning->settlement_batch_id !== null);
    }

    public function test_settlement_proceeds_only_after_reservation_release_commits(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 50000);

        // Committed precondition: everything is reserved by a live request.
        $request = app(InstructorWithdrawalServiceInterface::class)
            ->requestWithdrawal($instructor, $method, 50000, null, (string) Str::uuid());

        $results = $this->race([
            ['cancel', ['instructor_id' => $instructor->id, 'request_id' => $request->id]],
            // Retries until the released earning becomes settleable — it
            // must only ever succeed AFTER the release has committed.
            ['settle-retry', ['instructor_id' => $instructor->id, 'currency_code' => 'INR']],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        $earning->refresh();

        // Final state: reservation fully released, earning settled into a
        // batch — and at no point both (a reserved earning is invisible to
        // scopeSettleable, and release+status change share one transaction).
        $this->assertNotNull($earning->settlement_batch_id);
        $this->assertSame(0, (int) InstructorWithdrawalAllocation::query()
            ->where('instructor_earning_id', $earning->id)
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->count());
        $this->assertSame(50000, (int) InstructorWithdrawalAllocation::query()
            ->where('instructor_earning_id', $earning->id)
            ->where('status', WithdrawalAllocationStatus::Released)
            ->sum('amount_minor'));

        $batch = InstructorSettlementBatch::query()->forInstructor($instructor->id)->sole();
        $this->assertSame(50000, $batch->total_amount_minor);
    }
}
