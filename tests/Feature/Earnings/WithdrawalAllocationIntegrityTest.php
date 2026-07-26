<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reservation-ledger invariants, both the ones the services enforce
 * and the ones the database enforces on its own
 * (positive amounts via CHECK, uniqueness via iwa_request_earning_unique).
 */
class WithdrawalAllocationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private InstructorWithdrawalServiceInterface $withdrawals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawals = app(InstructorWithdrawalServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        $settings->maximum_active_requests_per_instructor = 5;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    public function test_database_check_constraint_rejects_non_positive_amounts(): void
    {
        [$request, $earning] = $this->requestWithEarning();

        $this->expectException(QueryException::class);

        DB::table('instructor_withdrawal_allocations')->insert([
            'id' => (string) Str::uuid(),
            'withdrawal_request_id' => $request->id,
            'instructor_earning_id' => $this->releasableEarning($request->instructor, 10000)->id,
            'currency_code' => 'INR',
            'amount_minor' => 0,
            'status' => 'reserved',
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_unique_constraint_rejects_duplicate_request_earning_pairs(): void
    {
        [$request, $earning] = $this->requestWithEarning();

        $this->expectException(QueryException::class);

        DB::table('instructor_withdrawal_allocations')->insert([
            'id' => (string) Str::uuid(),
            'withdrawal_request_id' => $request->id,
            'instructor_earning_id' => $earning->id,
            'currency_code' => 'INR',
            'amount_minor' => 1,
            'status' => 'reserved',
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_allocation_service_rejects_foreign_instructor_earnings(): void
    {
        [$request] = $this->requestWithEarning();
        $foreignEarning = $this->releasableEarning($this->makeInstructor(), 50000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('different instructor');

        app(InstructorWithdrawalAllocationServiceInterface::class)
            ->reserve($request, collect([$foreignEarning]));
    }

    public function test_allocation_service_rejects_currency_mismatched_earnings(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        [$request] = $this->requestWithEarning();
        $usdEarning = InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $request->instructor_id,
            'earning_amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('different currency');

        app(InstructorWithdrawalAllocationServiceInterface::class)
            ->reserve($request, collect([$usdEarning]));
    }

    public function test_per_earning_holds_never_exceed_the_earning_value(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 50000);

        // Two partial reservations against one earning.
        $this->withdrawals->requestWithdrawal($instructor, $method, 30000);
        $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        $held = (int) InstructorWithdrawalAllocation::query()
            ->where('instructor_earning_id', $earning->id)
            ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed])
            ->sum('amount_minor');

        $this->assertSame(50000, $held);
        $this->assertLessThanOrEqual($earning->earning_amount_minor, $held);

        // A third request has nothing left to reserve.
        $this->expectException(WithdrawalException::class);
        $this->withdrawals->requestWithdrawal($instructor, $method, 10000);
    }

    public function test_active_request_reserved_sums_match_amounts_and_terminal_ones_are_zero(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, 100000);

        $active = $this->withdrawals->requestWithdrawal($instructor, $method, 30000);
        $cancelled = $this->withdrawals->requestWithdrawal($instructor, $method, 20000);
        $this->withdrawals->cancelByInstructor($cancelled, $instructor);

        $reservedFor = fn (InstructorWithdrawalRequest $r): int => (int) $r->allocations()
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor');

        $this->assertSame(30000, $reservedFor($active));
        $this->assertSame(0, $reservedFor($cancelled->fresh()));

        // Released history rows persist — nothing was hard deleted.
        $this->assertSame(20000, (int) $cancelled->allocations()
            ->where('status', WithdrawalAllocationStatus::Released)
            ->sum('amount_minor'));
    }

    public function test_partial_allocations_stay_in_integer_minor_units(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, 33333);
        $this->releasableEarning($instructor, 33334);

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 44444);

        foreach ($request->allocations()->get() as $allocation) {
            $this->assertIsInt($allocation->amount_minor);
            $this->assertGreaterThan(0, $allocation->amount_minor);
        }

        $this->assertSame(44444, (int) $request->allocations()->sum('amount_minor'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0: InstructorWithdrawalRequest, 1: InstructorEarning} */
    private function requestWithEarning(): array
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 50000);

        return [$this->withdrawals->requestWithdrawal($instructor, $method, 30000), $earning];
    }

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function verifiedMethod(User $instructor): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);
    }

    private function releasableEarning(User $instructor, int $amountMinor): InstructorEarning
    {
        return InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => 'INR',
        ]);
    }
}
