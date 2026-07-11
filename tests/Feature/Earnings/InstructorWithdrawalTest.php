<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\InvalidWithdrawalTransitionException;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Earnings\Exceptions\WithdrawalException;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Notifications\Instructor\InstructorWithdrawalStatusNotification;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private InstructorWithdrawalServiceInterface $withdrawals;

    private InstructorWithdrawalBalanceServiceInterface $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawals = app(InstructorWithdrawalServiceInterface::class);
        $this->balances = app(InstructorWithdrawalBalanceServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        $this->settings(['withdrawals_enabled' => true, 'minimum_withdrawal_minor' => 10000]);
    }

    // ── Balance ──────────────────────────────────────────────────────

    public function test_balance_counts_only_releasable_unassigned_earnings(): void
    {
        $instructor = $this->makeInstructor();

        $this->earning($instructor, 30000);                                            // releasable → counts
        $this->earning($instructor, 40000, InstructorEarningStatus::PendingHold);      // still held
        $this->earning($instructor, 50000, InstructorEarningStatus::Reversed);         // reversed
        $this->earning($instructor, 60000, InstructorEarningStatus::Settled);          // already paid
        $this->earning($instructor, 70000, InstructorEarningStatus::DisputedHold);     // disputed

        $balance = $this->balances->calculate($instructor, 'INR');

        $this->assertSame(30000, $balance->grossEligibleMinor);
        $this->assertSame(0, $balance->reservedMinor);
        $this->assertSame(30000, $balance->availableMinor);
    }

    public function test_reserved_allocations_are_subtracted_and_released_ones_return(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        $balance = $this->balances->calculate($instructor, 'INR');
        $this->assertSame(50000, $balance->grossEligibleMinor);
        $this->assertSame(20000, $balance->reservedMinor);
        $this->assertSame(30000, $balance->availableMinor);

        $this->withdrawals->cancelByInstructor($request, $instructor);

        $balance = $this->balances->calculate($instructor, 'INR');
        $this->assertSame(0, $balance->reservedMinor);
        $this->assertSame(50000, $balance->availableMinor);
    }

    public function test_currency_balances_stay_separated(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 30000, currency: 'INR');
        $this->earning($instructor, 90000, currency: 'USD');

        $this->assertSame(30000, $this->balances->calculate($instructor, 'INR')->availableMinor);
        $this->assertSame(90000, $this->balances->calculate($instructor, 'USD')->availableMinor);
        $this->assertSame(['INR', 'USD'], $this->balances->currenciesWithBalance($instructor));
    }

    public function test_balance_is_integer_minor_units(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 33333);

        $balance = $this->balances->calculate($instructor, 'INR');

        $this->assertIsInt($balance->availableMinor);
        $this->assertIsInt($balance->grossEligibleMinor);
        $this->assertIsInt($balance->reservedMinor);
    }

    // ── Request validation ───────────────────────────────────────────

    public function test_withdrawals_disabled_setting_blocks_creation(): void
    {
        $this->settings(['withdrawals_enabled' => false]);
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $this->verifiedMethod($instructor), 20000);
    }

    public function test_minimum_amount_is_enforced(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('minimum');

        $this->withdrawals->requestWithdrawal($instructor, $this->verifiedMethod($instructor), 9999);
    }

    public function test_maximum_amount_is_enforced(): void
    {
        $this->settings(['maximum_withdrawal_minor' => 25000]);
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('maximum');

        $this->withdrawals->requestWithdrawal($instructor, $this->verifiedMethod($instructor), 30000);
    }

    public function test_insufficient_balance_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 15000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('available balance');

        $this->withdrawals->requestWithdrawal($instructor, $this->verifiedMethod($instructor), 20000);
    }

    public function test_unverified_payout_method_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);
        $draft = InstructorPayoutMethod::factory()->create(['instructor_id' => $instructor->id]);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $draft, 20000);
    }

    public function test_disabled_payout_method_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);
        $disabled = InstructorPayoutMethod::factory()->disabled()->create(['instructor_id' => $instructor->id]);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $disabled, 20000);
    }

    public function test_another_instructors_payout_method_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000);
        $foreign = $this->verifiedMethod($this->makeInstructor());

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('does not belong to you');

        $this->withdrawals->requestWithdrawal($instructor, $foreign, 20000);
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->earning($instructor, 50000, currency: 'INR');
        // Method pays out in USD but the instructor's earnings are INR —
        // there is no USD balance, so the request must fail.
        $usdMethod = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'USD',
        ]);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $usdMethod, 20000);
    }

    public function test_active_request_limit_is_enforced(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 100000);

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('maximum number of open withdrawal requests');

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000);
    }

    // ── Successful request ───────────────────────────────────────────

    public function test_successful_request_reserves_snapshots_and_balances(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 30000, 'Rent week');

        $this->assertSame(InstructorWithdrawalStatus::Submitted, $request->status);
        $this->assertMatchesRegularExpression('/^WD-\d{6}-[A-Z0-9]{8}$/', $request->reference);
        $this->assertSame(30000, $request->amount_minor);
        $this->assertSame(0, $request->fee_minor);
        $this->assertSame(30000, $request->net_amount_minor);
        $this->assertSame(50000, $request->available_balance_before_minor);
        $this->assertSame(20000, $request->available_balance_after_minor);
        $this->assertSame($method->display_label, $request->payout_method_label);

        // Reservation covers the amount exactly.
        $this->assertSame(30000, (int) $request->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->sum('amount_minor'));

        // Snapshot captured, versioned, and holding the real destination.
        $snapshot = $request->fresh()->encrypted_payout_method_snapshot;
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame($method->id, $snapshot['payout_method_id']);
        $this->assertSame($method->encrypted_details['account_number'], $snapshot['account_number']);
    }

    public function test_snapshot_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 30000);

        $raw = DB::table('instructor_withdrawal_requests')->where('id', $request->id)->value('encrypted_payout_method_snapshot');
        $accountNumber = $method->encrypted_details['account_number'];

        $this->assertStringNotContainsString($accountNumber, $raw);
        $this->assertStringNotContainsString('account_number', $raw);

        $json = json_encode($request->fresh()->toArray());
        $this->assertStringNotContainsString('encrypted_payout_method_snapshot', $json);
        $this->assertStringNotContainsString('internal_review_note', $json);
        $this->assertStringNotContainsString('idempotency_key', $json);
        $this->assertStringNotContainsString($accountNumber, $json);
    }

    public function test_snapshot_is_immutable_when_method_changes_or_is_disabled(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 30000);
        $original = $request->fresh()->encrypted_payout_method_snapshot;

        // An active withdrawal blocks disabling its method (by design), so
        // close the request first — history must still hold the snapshot.
        $this->withdrawals->cancelByInstructor($request, $instructor);

        $method->forceFill(['display_label' => 'Renamed label'])->save();
        app(InstructorPayoutMethodServiceInterface::class)
            ->disable($method->fresh(), $instructor);

        $this->assertSame($original, $request->fresh()->encrypted_payout_method_snapshot);
        $this->assertNotSame($method->fresh()->display_label, $request->fresh()->payout_method_label);
    }

    public function test_method_with_active_withdrawal_cannot_be_disabled(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);
        $this->withdrawals->requestWithdrawal($instructor, $method, 30000);

        $this->expectException(PayoutMethodException::class);

        app(InstructorPayoutMethodServiceInterface::class)
            ->disable($method->fresh(), $instructor);
    }

    public function test_fifo_allocation_is_deterministic_with_partial_split(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);

        $oldest = $this->earning($instructor, 10000, releasedAt: now()->subDays(3));
        $middle = $this->earning($instructor, 20000, releasedAt: now()->subDays(2));
        $newest = $this->earning($instructor, 30000, releasedAt: now()->subDay());

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 25000);

        $allocations = $request->allocations()->get()->keyBy('instructor_earning_id');

        $this->assertCount(2, $allocations);
        $this->assertSame(10000, $allocations[$oldest->id]->amount_minor);   // consumed whole
        $this->assertSame(15000, $allocations[$middle->id]->amount_minor);   // partial split
        $this->assertFalse($allocations->has($newest->id));                  // untouched
    }

    public function test_partially_reserved_earning_offers_only_its_remainder(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        $first = $this->withdrawals->requestWithdrawal($instructor, $method, 30000);
        $this->settings(['maximum_active_requests_per_instructor' => 2]);

        $second = $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        $this->assertSame(20000, (int) $second->allocations()->sum('amount_minor'));
        $this->assertSame(0, $this->balances->calculate($instructor, 'INR')->availableMinor);
    }

    public function test_concurrent_style_over_reservation_is_impossible(): void
    {
        $this->settings(['maximum_active_requests_per_instructor' => 5]);
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 30000);

        $this->withdrawals->requestWithdrawal($instructor, $method, 30000);

        // Everything is reserved — any further request must fail no matter
        // what balance the client believed it had.
        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $method, 10000);
    }

    public function test_same_idempotency_key_does_not_create_duplicates(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 100000);
        $key = (string) Str::uuid();

        $first = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);
        $replay = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, InstructorWithdrawalRequest::query()->count());
        $this->assertSame(20000, (int) InstructorWithdrawalAllocation::query()->sum('amount_minor'));
    }

    // ── Phase 14 boundary: settlement vs reservation ─────────────────

    public function test_reserved_earnings_leave_the_settlement_pool(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->earning($instructor, 50000);

        $this->withdrawals->requestWithdrawal($instructor, $method, 50000);

        $this->assertFalse(InstructorEarning::query()->settleable()->whereKey($earning->id)->exists());

        $this->expectException(EarningException::class);

        app(InstructorEarningServiceInterface::class)
            ->createSettlementBatch($instructor->id, 'INR');
    }

    public function test_batch_assigned_earnings_are_not_withdrawable(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 50000);

        app(InstructorEarningServiceInterface::class)->createSettlementBatch($instructor->id, 'INR');

        $this->assertSame(0, $this->balances->calculate($instructor, 'INR')->availableMinor);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000);
    }

    // ── Transitions ──────────────────────────────────────────────────

    public function test_review_required_blocks_direct_approval_from_submitted(): void
    {
        [$request] = $this->submittedRequest();
        $admin = $this->makeAdmin();

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('must be reviewed');

        $this->withdrawals->approve($request, $admin);
    }

    public function test_submitted_flows_through_review_to_approval_keeping_reservations(): void
    {
        [$request] = $this->submittedRequest();
        $admin = $this->makeAdmin();

        $request = $this->withdrawals->startReview($request, $admin);
        $this->assertSame(InstructorWithdrawalStatus::UnderReview, $request->status);
        $this->assertSame($admin->id, $request->review_started_by);

        $request = $this->withdrawals->approve($request, $admin);
        $this->assertSame(InstructorWithdrawalStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->approved_by);

        // Approval retains every reservation for the future payout run.
        $this->assertSame(
            $request->amount_minor,
            (int) $request->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->sum('amount_minor'),
        );
    }

    public function test_direct_approval_works_when_review_not_required(): void
    {
        $this->settings(['withdrawal_review_required' => false]);
        [$request] = $this->submittedRequest();

        $request = $this->withdrawals->approve($request, $this->makeAdmin());

        $this->assertSame(InstructorWithdrawalStatus::Approved, $request->status);
    }

    public function test_rejection_requires_reason_and_releases_reservations(): void
    {
        [$request, $instructor] = $this->submittedRequest();
        $admin = $this->makeAdmin();

        try {
            $this->withdrawals->reject($request, $admin, '  ');
            $this->fail('Blank reason should have been rejected.');
        } catch (WithdrawalException) {
        }

        $request = $this->withdrawals->reject($request, $admin, 'Bank account mismatch.');

        $this->assertSame(InstructorWithdrawalStatus::Rejected, $request->status);
        $this->assertSame('Bank account mismatch.', $request->rejection_reason);
        $this->assertSame(0, $request->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->count());
        $this->assertNotNull($request->allocations()->first()->released_at);
        $this->assertSame(50000, $this->balances->calculate($instructor, 'INR')->availableMinor);
    }

    public function test_instructor_can_cancel_own_eligible_request(): void
    {
        [$request, $instructor] = $this->submittedRequest();

        $request = $this->withdrawals->cancelByInstructor($request, $instructor);

        $this->assertSame(InstructorWithdrawalStatus::Cancelled, $request->status);
        $this->assertSame(0, $request->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->count());
    }

    public function test_instructor_cannot_cancel_someone_elses_request(): void
    {
        [$request] = $this->submittedRequest();

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->cancelByInstructor($request, $this->makeInstructor());
    }

    public function test_cancellation_setting_blocks_instructor_cancel(): void
    {
        [$request, $instructor] = $this->submittedRequest();
        $this->settings(['instructor_cancellation_enabled' => false]);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->cancelByInstructor($request, $instructor);
    }

    public function test_approved_request_cannot_be_cancelled_by_instructor(): void
    {
        [$request, $instructor] = $this->submittedRequest();
        $admin = $this->makeAdmin();
        $this->withdrawals->startReview($request, $admin);
        $request = $this->withdrawals->approve($request, $admin);

        $this->expectException(WithdrawalException::class);

        $this->withdrawals->cancelByInstructor($request, $instructor);
    }

    public function test_terminal_states_reject_further_transitions(): void
    {
        [$request] = $this->submittedRequest();
        $admin = $this->makeAdmin();
        $request = $this->withdrawals->reject($request, $admin, 'No.');

        try {
            $this->withdrawals->startReview($request, $admin);
            $this->fail('Rejected request must not enter review.');
        } catch (InvalidWithdrawalTransitionException) {
        }

        $this->expectException(WithdrawalException::class);
        $this->withdrawals->approve($request, $admin);
    }

    public function test_approval_fails_when_reservation_integrity_is_broken(): void
    {
        [$request] = $this->submittedRequest();
        $admin = $this->makeAdmin();
        $this->withdrawals->startReview($request, $admin);

        // Simulate a leak: one allocation released outside the workflow.
        $request->allocations()->first()->forceFill([
            'status' => WithdrawalAllocationStatus::Released,
            'released_at' => now(),
        ])->save();

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('integrity');

        $this->withdrawals->approve($request->fresh(), $admin);
    }

    // ── Notifications ────────────────────────────────────────────────

    public function test_lifecycle_notifies_the_instructor_safely_on_the_queue(): void
    {
        Notification::fake();

        [$request, $instructor] = $this->submittedRequest();

        Notification::assertSentTo($instructor, InstructorWithdrawalStatusNotification::class, function ($notification) use ($instructor, $request) {
            $payload = json_encode($notification->toArray($instructor));
            $accountNumber = $request->fresh()->encrypted_payout_method_snapshot['account_number'];

            return $notification->queue === 'notifications'
                && ! str_contains($payload, $accountNumber);
        });
    }

    public function test_failed_request_sends_no_notification(): void
    {
        Notification::fake();

        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, 15000);

        try {
            $this->withdrawals->requestWithdrawal($instructor, $method, 99000);
        } catch (WithdrawalException) {
        }

        Notification::assertNotSentTo($instructor, InstructorWithdrawalStatusNotification::class);
        $this->assertSame(0, InstructorWithdrawalRequest::query()->count());
        $this->assertSame(0, InstructorWithdrawalAllocation::query()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $permissions = [
            'StartReview:InstructorWithdrawalRequest', 'Approve:InstructorWithdrawalRequest',
            'Reject:InstructorWithdrawalRequest', 'Cancel:InstructorWithdrawalRequest',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function verifiedMethod(User $instructor): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);
    }

    private function earning(
        User $instructor,
        int $amountMinor,
        InstructorEarningStatus $status = InstructorEarningStatus::Releasable,
        string $currency = 'INR',
        ?CarbonInterface $releasedAt = null,
    ): InstructorEarning {
        $factory = InstructorEarning::factory();

        if ($status === InstructorEarningStatus::Releasable) {
            $factory = $factory->releasable();
        }

        return $factory->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => $currency,
            'status' => $status,
            'released_at' => $status === InstructorEarningStatus::Releasable ? ($releasedAt ?? now()->subDay()) : null,
        ]);
    }

    /** @return array{0: InstructorWithdrawalRequest, 1: User} */
    private function submittedRequest(int $earningMinor = 50000, int $amountMinor = 30000): array
    {
        $instructor = $this->makeInstructor();
        $method = $this->verifiedMethod($instructor);
        $this->earning($instructor, $earningMinor);

        return [$this->withdrawals->requestWithdrawal($instructor, $method, $amountMinor), $instructor];
    }

    private function settings(array $overrides): void
    {
        $settings = app(InstructorEarningSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
