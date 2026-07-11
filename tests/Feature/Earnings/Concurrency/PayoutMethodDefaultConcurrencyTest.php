<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Models\InstructorPayoutMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Two real processes set two different verified methods as default
 * simultaneously. The owner-row lock serializes the switches (either
 * order is a legitimate outcome), and the generated-column unique index
 * (ipm_active_default_owner_unique) is the database backstop: even with
 * the service lock removed, two active verified defaults for one
 * instructor cannot exist.
 */
class PayoutMethodDefaultConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_default_switches_leave_exactly_one_default(): void
    {
        $instructor = $this->makeInstructor();
        $first = $this->verifiedMethod($instructor);
        $second = $this->verifiedMethod($instructor);

        $results = $this->race([
            ['set-default', ['instructor_id' => $instructor->id, 'method_id' => $first->id]],
            ['set-default', ['instructor_id' => $instructor->id, 'method_id' => $second->id]],
        ]);

        // Serialized switches: both succeed (the later one wins), or one
        // fails safely — but never two defaults.
        $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn (array $r): bool => $r['ok'])), json_encode($results));

        $this->assertSame(1, InstructorPayoutMethod::query()
            ->forInstructor($instructor->id)
            ->where('is_default', true)
            ->count());
    }

    public function test_database_backstop_rejects_a_second_active_default_outright(): void
    {
        $instructor = $this->makeInstructor();
        $this->verifiedMethod($instructor)->forceFill(['is_default' => true])->save();
        $second = $this->verifiedMethod($instructor);

        // Bypass the service entirely — the unique generated column must
        // still refuse a second (default, verified, not-deleted) row.
        $this->expectException(QueryException::class);

        DB::table('instructor_payout_methods')
            ->where('id', $second->id)
            ->update(['is_default' => true]);
    }
}
