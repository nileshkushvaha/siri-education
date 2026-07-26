<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Approval re-verifies every financial invariant on locked rows and
 * fails atomically on any breach. Nothing is silently
 * repaired: each scenario below corrupts one invariant out-of-band and
 * proves approval refuses, leaving the request in its prior state.
 */
class WithdrawalApprovalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private InstructorWithdrawalServiceInterface $withdrawals;

    private User $admin;

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
        // Approval integrity is the subject here — not the review gate.
        $settings->withdrawal_review_required = false;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Permission::firstOrCreate(['name' => 'Approve:InstructorWithdrawalRequest', 'guard_name' => 'web']);
        $this->admin->givePermissionTo('Approve:InstructorWithdrawalRequest');
    }

    public function test_intact_request_approves_and_keeps_reservations(): void
    {
        [$request] = $this->submittedRequest();

        $approved = $this->withdrawals->approve($request, $this->admin);

        $this->assertSame(InstructorWithdrawalStatus::Approved, $approved->status);
        $this->assertSame(30000, (int) $approved->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->sum('amount_minor'));
    }

    public function test_broken_fee_net_reconciliation_blocks_approval(): void
    {
        [$request] = $this->submittedRequest();
        $request->forceFill(['net_amount_minor' => 29999])->save();

        $this->assertApprovalFails($request, 'fee and net amount');
    }

    public function test_leaked_reservation_blocks_approval(): void
    {
        [$request] = $this->submittedRequest();
        $request->allocations()->first()->forceFill([
            'status' => WithdrawalAllocationStatus::Released,
            'released_at' => now(),
        ])->save();

        $this->assertApprovalFails($request, 'Reservation integrity');
    }

    public function test_reversed_backing_earning_blocks_approval(): void
    {
        [$request, $earning] = $this->submittedRequest();
        $earning->forceFill(['status' => InstructorEarningStatus::Reversed, 'reversed_at' => now()])->save();

        $this->assertApprovalFails($request, 'no longer available');
    }

    public function test_disputed_backing_earning_blocks_approval(): void
    {
        [$request, $earning] = $this->submittedRequest();
        $earning->forceFill(['status' => InstructorEarningStatus::DisputedHold])->save();

        $this->assertApprovalFails($request, 'no longer available');
    }

    public function test_settlement_assigned_backing_earning_blocks_approval(): void
    {
        [$request, $earning, $instructor] = $this->submittedRequest();

        // Force the cross-path corruption the locks normally prevent:
        // hand the reserved earning to a settlement batch out-of-band.
        $batch = InstructorSettlementBatch::factory()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
        ]);
        $earning->forceFill(['settlement_batch_id' => $batch->id])->save();

        $this->assertApprovalFails($request, 'no longer available');
    }

    public function test_disabled_payout_method_blocks_approval(): void
    {
        [$request] = $this->submittedRequest();

        // Bypass the service (which would refuse to disable a method with
        // an active withdrawal) to simulate legacy/corrupt data.
        InstructorPayoutMethod::query()->whereKey($request->payout_method_id)
            ->update(['status' => PayoutMethodStatus::Disabled->value, 'disabled_at' => now()]);

        $this->assertApprovalFails($request, 'no longer active');
    }

    public function test_undecryptable_snapshot_blocks_approval_safely(): void
    {
        [$request] = $this->submittedRequest();

        DB::table('instructor_withdrawal_requests')
            ->where('id', $request->id)
            ->update(['encrypted_payout_method_snapshot' => 'corrupted-not-a-ciphertext']);

        try {
            $this->withdrawals->approve($request->fresh(), $this->admin);
            $this->fail('Approval should have failed.');
        } catch (WithdrawalException $e) {
            $this->assertStringContainsString('cannot be decrypted', $e->getMessage());
            $this->assertStringNotContainsString('corrupted-not-a-ciphertext', $e->getMessage());
        }

        $this->assertSame(InstructorWithdrawalStatus::Submitted, $request->fresh()->status);
    }

    public function test_missing_snapshot_blocks_approval(): void
    {
        [$request] = $this->submittedRequest();
        $request->forceFill(['encrypted_payout_method_snapshot' => []])->save();

        $this->assertApprovalFails($request, 'snapshot is missing');
    }

    public function test_failed_approval_changes_nothing(): void
    {
        [$request] = $this->submittedRequest();
        $request->allocations()->first()->forceFill(['status' => WithdrawalAllocationStatus::Released, 'released_at' => now()])->save();

        try {
            $this->withdrawals->approve($request->fresh(), $this->admin);
        } catch (WithdrawalException) {
        }

        $fresh = $request->fresh();
        $this->assertSame(InstructorWithdrawalStatus::Submitted, $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function assertApprovalFails(InstructorWithdrawalRequest $request, string $messageFragment): void
    {
        try {
            $this->withdrawals->approve($request->fresh(), $this->admin);
            $this->fail('Approval should have failed: '.$messageFragment);
        } catch (WithdrawalException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }

        $this->assertSame(InstructorWithdrawalStatus::Submitted, $request->fresh()->status);
    }

    /** @return array{0: InstructorWithdrawalRequest, 1: InstructorEarning, 2: User} */
    private function submittedRequest(): array
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update(['instructor_status' => InstructorStatus::Active]);

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        $earning = InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => 30000,
            'currency_code' => 'INR',
        ]);

        return [$this->withdrawals->requestWithdrawal($instructor, $method, 30000), $earning, $instructor];
    }
}
