<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use Illuminate\Support\Str;

/**
 * Two real processes race to withdraw the same available earnings on
 * separate MySQL connections. The instructor-row lock is the invariant
 * under test: without it, both requests read the same pre-lock balance
 * and both reserve — with it, they serialize and the second recalculates
 * against the already-reserved pool.
 */
class WithdrawalReservationConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_withdrawals_cannot_over_reserve_the_same_balance(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 30000);

        $results = $this->race([
            ['withdraw', ['instructor_id' => $instructor->id, 'method_id' => $method->id, 'amount_minor' => 30000, 'idempotency_key' => (string) Str::uuid()]],
            ['withdraw', ['instructor_id' => $instructor->id, 'method_id' => $method->id, 'amount_minor' => 30000, 'idempotency_key' => (string) Str::uuid()]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        // The balance supports exactly one of the two requests.
        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed);
        $this->assertSame(WithdrawalException::class, $failed[0]['exception']);

        // No duplicate request, no duplicate allocation.
        $this->assertSame(1, InstructorWithdrawalRequest::query()->forInstructor($instructor->id)->count());

        // Live reservations never exceed the earning's value.
        $reserved = (int) InstructorWithdrawalAllocation::query()
            ->where('instructor_earning_id', $earning->id)
            ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed])
            ->sum('amount_minor');

        $this->assertSame(30000, $reserved);
        $this->assertLessThanOrEqual($earning->earning_amount_minor, $reserved);

        // No negative available balance.
        $balance = app(InstructorWithdrawalBalanceServiceInterface::class)->calculate($instructor, 'INR');
        $this->assertSame(0, $balance->availableMinor);
        $this->assertGreaterThanOrEqual(0, $balance->availableMinor);
    }

    public function test_concurrent_replays_of_one_idempotency_key_create_one_request(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, 100000);

        $key = (string) Str::uuid();

        $results = $this->race([
            ['withdraw', ['instructor_id' => $instructor->id, 'method_id' => $method->id, 'amount_minor' => 20000, 'idempotency_key' => $key]],
            ['withdraw', ['instructor_id' => $instructor->id, 'method_id' => $method->id, 'amount_minor' => 20000, 'idempotency_key' => $key]],
        ]);

        // Both callers get a safe outcome (the replay returns the original
        // request), but only one request and one reservation ever exist.
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        $this->assertSame(
            $results[0]['result']['request_id'],
            $results[1]['result']['request_id'],
            'Both workers must resolve to the same withdrawal request.',
        );

        $this->assertSame(1, InstructorWithdrawalRequest::query()->forInstructor($instructor->id)->count());
        $this->assertSame(20000, (int) InstructorWithdrawalAllocation::query()
            ->whereIn('withdrawal_request_id', InstructorWithdrawalRequest::query()->forInstructor($instructor->id)->select('id'))
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor'));
    }
}
