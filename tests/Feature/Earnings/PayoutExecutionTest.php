<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\PayoutRequestFingerprintServiceInterface;
use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Providers\Fake\FakeInstructorPayoutProvider;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorPayoutProviderEvent;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Notifications\Instructor\InstructorWithdrawalStatusNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\InstructorPayoutExecutionPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Provider-neutral payout execution. Covers §33: provider contract,
 * maker-checker, execution integrity, success/failure/
 * reversal finalization, partial allocations, event processing,
 * reconciliation, and policies. Real multi-process races live in
 * tests/Feature/Earnings/Concurrency/PayoutExecutionConcurrencyTest.
 */
class PayoutExecutionTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'withdrawals_enabled' => true,
            'withdrawal_review_required' => false,
            'minimum_withdrawal_minor' => 1000,
            'maximum_active_requests_per_instructor' => 5,
            'payout_execution_enabled' => true,
            'payout_provider' => 'fake',
            'payout_maker_checker_enabled' => true,
            'payout_auto_retry_enabled' => false,
        ]);
    }

    // ── Provider contract ─────────────────────────────────────────────

    public function test_fake_provider_resolves_and_razorpayx_is_also_registered(): void
    {
        $registry = app(InstructorPayoutProviderRegistryInterface::class);
        $this->assertTrue($registry->has('fake'));
        // An adapter exists, but it is disabled/uncredentialed by
        // default, so resolving it still fails (see the unhealthy-
        // provider test below).
        $this->assertTrue($registry->has('razorpayx'));

        $provider = app(InstructorPayoutProviderResolverInterface::class)->resolve('fake', 'INR');
        $this->assertInstanceOf(FakeInstructorPayoutProvider::class, $provider);
    }

    public function test_unregistered_provider_is_rejected(): void
    {
        $this->expectException(PayoutProviderException::class);
        app(InstructorPayoutProviderResolverInterface::class)->resolve('stripe_connect', 'INR');
    }

    public function test_registered_but_unconfigured_razorpayx_provider_is_rejected(): void
    {
        // Registered (an adapter exists) but never enabled/credentialed —
        // resolve() must still refuse it via the health check, exactly
        // like any other misconfigured provider.
        $this->expectException(PayoutProviderException::class);
        app(InstructorPayoutProviderResolverInterface::class)->resolve('razorpayx', 'INR');
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $this->expectException(PayoutProviderException::class);
        app(InstructorPayoutProviderResolverInterface::class)->resolve('fake', 'XXX');
    }

    public function test_fake_provider_same_idempotency_key_and_scenario_returns_the_same_payout_id(): void
    {
        $provider = app(InstructorPayoutProviderResolverInterface::class)->resolve('fake', 'INR');

        $request = fn (): PayoutInitiationRequest => new PayoutInitiationRequest(
            attemptReference: 'PA-TEST', withdrawalReference: 'WD-TEST', amountMinor: 1000,
            currencyCode: 'INR', idempotencyKey: 'fixed-key', destinationSnapshot: ['schema_version' => 1],
            purpose: 'test',
        );

        $first = $provider->initiate($request());
        $second = $provider->initiate($request());

        $this->assertSame($first->providerPayoutId, $second->providerPayoutId);
    }

    public function test_request_fingerprint_is_deterministic_and_amount_sensitive(): void
    {
        $fingerprints = app(PayoutRequestFingerprintServiceInterface::class);
        $withdrawal = InstructorWithdrawalRequest::factory()->approved()->create(['amount_minor' => 10000]);
        $snapshot = ['schema_version' => 1, 'masked_identifier' => 'x'];

        $a = $fingerprints->generate($withdrawal, 1, 'fake', $snapshot, 'purpose');
        $b = $fingerprints->generate($withdrawal, 1, 'fake', $snapshot, 'purpose');
        $this->assertSame($a, $b, 'Same inputs must produce the same fingerprint.');

        $withdrawal->amount_minor = 20000;
        $c = $fingerprints->generate($withdrawal, 1, 'fake', $snapshot, 'purpose');
        $this->assertNotSame($a, $c, 'A changed amount must change the fingerprint.');
    }

    public function test_fake_provider_never_makes_a_network_call(): void
    {
        $code = file_get_contents(app_path('Earnings/Providers/Fake/FakeInstructorPayoutProvider.php'));
        foreach (['Http::', 'curl_init', 'GuzzleHttp', 'file_get_contents(\'http'] as $needle) {
            $this->assertStringNotContainsString($needle, $code);
        }
    }

    // ── Maker-checker ─────────────────────────────────────────────────

    public function test_approver_cannot_execute_their_own_approved_withdrawal(): void
    {
        [$withdrawal, , $approver] = $this->approvedWithdrawal(20000);

        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $approver);
    }

    public function test_a_different_finance_user_can_execute(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $executor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $executor);

        $this->assertSame($executor->id, $attempt->initiated_by);
    }

    public function test_maker_checker_disabled_allows_the_approver_to_execute(): void
    {
        $this->setFinancialSettings(['payout_maker_checker_enabled' => false]);
        [$withdrawal, , $approver] = $this->approvedWithdrawal(20000);

        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $approver);

        $this->assertNotNull($attempt);
        $this->setFinancialSettings(['payout_maker_checker_enabled' => true]);
    }

    public function test_instructor_is_denied_execute_and_retry_permissions(): void
    {
        [$withdrawal, $instructor] = $this->approvedWithdrawal(20000);

        $this->assertFalse($instructor->can('executePayout', $withdrawal));
        $this->assertFalse($instructor->can('retryPayout', $withdrawal));
    }

    // ── Execution integrity ───────────────────────────────────────────

    public function test_only_an_approved_withdrawal_can_be_queued(): void
    {
        $instructor = $this->instructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, 20000);
        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal($instructor, $method, 20000, null, (string) Str::uuid());

        $this->assertSame(InstructorWithdrawalStatus::Submitted, $withdrawal->status);

        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());
    }

    public function test_execution_disabled_switch_blocks_the_provider_entirely(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $this->setFinancialSettings(['payout_execution_enabled' => false]);

        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());
    }

    public function test_snapshot_is_required_to_queue_execution(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        // Directly corrupt the persisted snapshot to empty (bypassing the service, simulating data loss).
        DB::table('instructor_withdrawal_requests')
            ->where('id', $withdrawal->id)
            ->update(['encrypted_payout_method_snapshot' => encrypt([])]);

        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal->fresh(), $this->executor());
    }

    public function test_queueing_creates_exactly_one_attempt_with_sequence_one(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);

        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        $attempts = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->get();
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts->first()->execution_sequence);
    }

    // ── Success finalization ──────────────────────────────────────────

    public function test_provider_confirmed_success_marks_paid_and_consumes_allocations(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(25000);

        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);
        $this->assertNotNull($withdrawal->paid_at);

        $this->assertSame(25000, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Consumed)
            ->sum('amount_minor'));

        $attempt = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->sole();
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_partial_earning_remainder_stays_available_after_a_smaller_withdrawal_is_paid(): void
    {
        $instructor = $this->instructor();
        $method = $this->verifiedMethod($instructor);
        $earning = $this->releasableEarning($instructor, 10000);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal($instructor, $method, 6000, null, (string) Str::uuid());
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, $this->approver());
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        // 6,000 consumed, 4,000 must remain reservable/settleable.
        $balance = app(InstructorWithdrawalBalanceServiceInterface::class)->calculate($instructor, 'INR');
        $this->assertSame(4000, $balance->availableMinor);

        // The earning is still Releasable and no longer settleable for
        // its ORIGINAL amount, but a second withdrawal for the remainder succeeds.
        $second = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal($instructor, $method, 4000, null, (string) Str::uuid());
        $this->assertSame(4000, $second->amount_minor);

        $earning->refresh();
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->status);
    }

    public function test_settlement_excludes_a_fully_consumed_earning(): void
    {
        $instructor = $this->instructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, 15000);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal($instructor, $method, 15000, null, (string) Str::uuid());
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, $this->approver());
        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        $this->expectException(EarningException::class);
        app(InstructorEarningServiceInterface::class)->createSettlementBatch($instructor->id, 'INR');
    }

    public function test_success_notifies_the_instructor_exactly_once(): void
    {
        [$withdrawal, $instructor] = $this->approvedWithdrawal(20000);

        Notification::fake();

        app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        // Only the "paid" notification fires here — request/approve
        // already happened (and notified) before the fake was installed.
        Notification::assertSentTimes(InstructorWithdrawalStatusNotification::class, 1);
        Notification::assertSentTo(
            $instructor,
            InstructorWithdrawalStatusNotification::class,
            fn (InstructorWithdrawalStatusNotification $n): bool => $n->toArray($instructor)['status'] === InstructorWithdrawalStatus::Paid->value,
        );
    }

    // ── Failure finalization ──────────────────────────────────────────

    public function test_pre_acceptance_failure_returns_the_withdrawal_to_approved(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);

        $this->queueWithScenario($withdrawal, $this->executor(), 'timeout_before_acceptance');

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Approved, $withdrawal->status, 'A pre-acceptance failure must return to approved automatically.');

        // Reservations are untouched — still fully reserved.
        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor'));
    }

    public function test_confirmed_permanent_failure_marks_failed_and_releases_reservations(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);

        $this->queueWithScenario($withdrawal, $this->executor(), 'failure_permanent');

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Failed, $withdrawal->status);
        $this->assertNotNull($withdrawal->failure_reason);

        $this->assertSame(0, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor'));
        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Released)
            ->sum('amount_minor'));
    }

    public function test_unknown_outcome_keeps_the_withdrawal_processing_and_retains_reservations(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);

        $this->queueWithScenario($withdrawal, $this->executor(), 'unknown');

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Processing, $withdrawal->status);
        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->sum('amount_minor'));

        $attempt = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->sole();
        $this->assertSame(InstructorPayoutAttemptStatus::Unknown, $attempt->status);
        $this->assertTrue(InstructorPayoutReconciliationIssue::query()
            ->open()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('type', PayoutReconciliationIssueType::UnknownProviderOutcome)
            ->exists());
    }

    public function test_failed_withdrawal_recovery_requires_intact_reservations(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $this->queueWithScenario($withdrawal, $this->executor(), 'failure_permanent');
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Failed, $withdrawal->status);

        // Reservations were released by the permanent failure — recovery must refuse.
        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutExecutionServiceInterface::class)->retry($withdrawal, $this->executor(), 'Attempting recovery.');
    }

    // ── Reversal ───────────────────────────────────────────────────────

    public function test_reversal_after_success_restores_the_balance_and_creates_an_issue(): void
    {
        [$withdrawal, $instructor] = $this->approvedWithdrawal(20000);
        $attempt = $this->queueWithScenario($withdrawal, $this->executor(), 'reversed_after_success');

        // Initiate reports Succeeded immediately (per the fake provider's
        // reversed_after_success scenario) — reconciliation discovers the
        // reversal on the next status poll.
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);

        app(InstructorPayoutReconciliationServiceInterface::class)->reconcileAttempt($attempt->fresh());

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Reversed, $withdrawal->status);
        $this->assertNotNull($withdrawal->reversed_at);

        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Reversed)
            ->sum('amount_minor'));

        $balance = app(InstructorWithdrawalBalanceServiceInterface::class)->calculate($instructor, 'INR');
        $this->assertSame(20000, $balance->availableMinor, 'A reversed allocation must become available again.');

        $this->assertTrue(InstructorPayoutReconciliationIssue::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('type', PayoutReconciliationIssueType::ReversedPayout)
            ->exists());
    }

    public function test_reversal_is_idempotent(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $attempt = $this->queueWithScenario($withdrawal, $this->executor(), 'reversed_after_success');

        app(InstructorPayoutReconciliationServiceInterface::class)->reconcileAttempt($attempt->fresh());
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Reversed, $withdrawal->status);

        // A second reconciliation pass must not double-reverse anything.
        app(InstructorPayoutReconciliationServiceInterface::class)->reconcileAttempt($attempt->fresh());

        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Reversed)
            ->sum('amount_minor'), 'The reversed amount must not double.');
    }

    // ── Event processing ─────────────────────────────────────────────

    public function test_duplicate_provider_event_has_no_duplicate_financial_effect(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());

        $event = $this->succeededEvent($attempt, 'evt-1');

        app(InstructorPayoutExecutionServiceInterface::class)->handleNormalizedEvent($event);
        app(InstructorPayoutExecutionServiceInterface::class)->handleNormalizedEvent($event);

        $this->assertSame(1, InstructorPayoutProviderEvent::query()->where('provider_event_id', 'evt-1')->count());
        $this->assertSame(1, InstructorPayoutProviderEvent::query()->where('provider_event_id', 'like', 'evt-1:dup:%')->count());
        $this->assertSame($withdrawal->amount_minor, (int) InstructorWithdrawalAllocation::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', WithdrawalAllocationStatus::Consumed)
            ->sum('amount_minor'));
    }

    public function test_unknown_provider_reference_creates_no_financial_effect(): void
    {
        $event = new NormalizedPayoutEvent(
            provider: 'fake', providerEventId: 'evt-unknown', eventType: 'payout.succeeded',
            providerPayoutId: 'fake_success_immediate_doesnotexist', attemptStatus: InstructorPayoutAttemptStatus::Succeeded,
            amountMinor: 1000, currencyCode: 'INR', occurredAt: CarbonImmutable::now(),
            payloadHash: 'x', signatureValid: true,
        );

        app(InstructorPayoutExecutionServiceInterface::class)->handleNormalizedEvent($event);

        $row = InstructorPayoutProviderEvent::query()->where('provider_event_id', 'evt-unknown')->sole();
        $this->assertSame('invalid', $row->processing_status);
        $this->assertSame(0, InstructorWithdrawalAllocation::query()->where('status', WithdrawalAllocationStatus::Consumed)->count());
    }

    public function test_amount_mismatch_event_creates_a_critical_reconciliation_issue(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $this->executor());
        $attempt->refresh();

        $event = new NormalizedPayoutEvent(
            provider: 'fake', providerEventId: 'evt-mismatch', eventType: 'payout.succeeded',
            providerPayoutId: $attempt->provider_payout_id, attemptStatus: InstructorPayoutAttemptStatus::Succeeded,
            amountMinor: $attempt->amount_minor + 100, currencyCode: 'INR', occurredAt: CarbonImmutable::now(),
            payloadHash: 'x', signatureValid: true,
        );

        app(InstructorPayoutExecutionServiceInterface::class)->handleNormalizedEvent($event);

        $this->assertTrue(InstructorPayoutReconciliationIssue::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('type', PayoutReconciliationIssueType::AmountMismatch)
            ->where('severity', PayoutReconciliationSeverity::Critical)
            ->exists());
    }

    // ── Reconciliation ────────────────────────────────────────────────

    public function test_reconciliation_resolves_a_pending_attempt_to_success(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $attempt = $this->queueWithScenario($withdrawal, $this->executor(), 'success_async');
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Processing, $withdrawal->status);

        app(InstructorPayoutReconciliationServiceInterface::class)->reconcileAttempt($attempt->fresh());

        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);
    }

    public function test_manual_resolution_never_marks_a_withdrawal_paid(): void
    {
        [$withdrawal] = $this->approvedWithdrawal(20000);
        $this->queueWithScenario($withdrawal, $this->executor(), 'unknown');

        $issue = InstructorPayoutReconciliationIssue::query()->open()->where('withdrawal_request_id', $withdrawal->id)->sole();

        app(InstructorPayoutReconciliationServiceInterface::class)->resolve($issue, $this->executor(), 'operational_fixed', 'Confirmed manually with finance team.');

        $issue->refresh();
        $this->assertSame(PayoutReconciliationIssueStatus::Resolved, $issue->status);

        // Resolving the ISSUE never touches the withdrawal's own status.
        $withdrawal->refresh();
        $this->assertSame(InstructorWithdrawalStatus::Processing, $withdrawal->status);
    }

    public function test_resolve_requires_a_mandatory_note(): void
    {
        $issue = InstructorPayoutReconciliationIssue::factory()->create();

        $this->expectException(PayoutExecutionException::class);
        app(InstructorPayoutReconciliationServiceInterface::class)->resolve($issue, $this->executor(), 'other', '');
    }

    // ── Policies ──────────────────────────────────────────────────────

    public function test_permission_seeder_grants_manager_and_denies_instructor(): void
    {
        $this->seed(InstructorPayoutExecutionPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $attempt = InstructorPayoutAttempt::factory()->create();

        $this->assertTrue($manager->can('viewAny', InstructorPayoutAttempt::class));
        $this->assertTrue($manager->can('cancel', $attempt));

        $instructor = $this->instructor();
        $this->assertFalse($instructor->can('viewAny', InstructorPayoutAttempt::class));
        $this->assertFalse($instructor->can('cancel', $attempt));
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return array{0: InstructorWithdrawalRequest, 1: User, 2: User} */
    private function approvedWithdrawal(int $amountMinor): array
    {
        $instructor = $this->instructor();
        $method = $this->verifiedMethod($instructor);
        $this->releasableEarning($instructor, $amountMinor);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal(
            $instructor, $method, $amountMinor, null, (string) Str::uuid(),
        );

        $approver = $this->approver();
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, $approver);

        return [$withdrawal, $instructor, $approver];
    }

    /**
     * QUEUE_CONNECTION=sync means queueExecution() would normally run
     * execute() inline, before a test can set a fake-provider scenario
     * on the freshly created attempt. Swap to a non-executing driver for
     * the queue step, patch the scenario, then run execute() manually —
     * exercising the exact same code path with deterministic control.
     */
    private function queueWithScenario(InstructorWithdrawalRequest $withdrawal, User $executor, ?string $scenario): InstructorPayoutAttempt
    {
        $originalConnection = config('queue.default');
        config(['queue.default' => 'database']);

        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, $executor);

        config(['queue.default' => $originalConnection]);

        if ($scenario !== null) {
            InstructorPayoutAttempt::query()->whereKey($attempt->id)->update(['requested_fake_scenario' => $scenario]);
        }

        app(InstructorPayoutExecutionServiceInterface::class)->execute($attempt->fresh());

        return $attempt->fresh();
    }

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function approver(): User
    {
        return User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function executor(): User
    {
        return User::factory()->create(['status' => User::STATUS_ACTIVE]);
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
            'status' => InstructorEarningStatus::Releasable,
        ]);
    }

    private function succeededEvent(InstructorPayoutAttempt $attempt, string $eventId): NormalizedPayoutEvent
    {
        return new NormalizedPayoutEvent(
            provider: $attempt->provider,
            providerEventId: $eventId,
            eventType: 'payout.succeeded',
            providerPayoutId: $attempt->provider_payout_id,
            attemptStatus: InstructorPayoutAttemptStatus::Succeeded,
            amountMinor: $attempt->amount_minor,
            currencyCode: $attempt->currency_code,
            occurredAt: CarbonImmutable::now(),
            payloadHash: hash('sha256', $eventId),
            signatureValid: true,
        );
    }
}
