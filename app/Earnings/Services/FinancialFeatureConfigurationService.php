<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Console\Commands\AccruePeriodicCompensation;
use App\Console\Commands\ReconcileInstructorPayouts;
use App\Console\Commands\ReleaseInstructorEarnings;
use App\Console\Commands\RetryBlockedLessons;
use App\Earnings\Contracts\FinancialFeatureConfigurationServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\DTOs\FeatureReadiness;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Enums\PayoutRolloutScope;
use App\Earnings\Exceptions\CompensationException;
use App\Earnings\Providers\RazorpayX\RazorpayXInstructorPayoutProvider;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutConfigurationValidator;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationException;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use App\Settings\RazorpayXPayoutSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical (and only) write path for the three financial feature
 * switches. Enable operations run their preflight and refuse on any
 * failure regardless of caller — Filament, commands, seeders, and
 * programmatic callers all pass through here, and the settings class
 * rejects switch writes from anywhere else. Chosen rule (documented):
 * disabling earnings transactionally auto-disables periodic
 * compensation, since periodic accrual is meaningless without the
 * earnings pipeline. Withdrawal enablement does NOT require Phase 16
 * provider credentials — requests only reserve earnings.
 */
final class FinancialFeatureConfigurationService implements FinancialFeatureConfigurationServiceInterface
{
    private const string LOG_NAME = 'instructor_earnings';

    public function __construct(
        private readonly InstructorEarningSettings $settings,
        private readonly CompensationActivationPreflight $preflight,
        private readonly AuditTrailService $audit,
        private readonly InstructorPayoutProviderRegistryInterface $providers,
        private readonly RazorpayXPayoutSettings $razorpayXSettings,
        private readonly RazorpayXPayoutConfigurationValidator $razorpayXConfigValidator,
    ) {}

    // ── Readiness evaluation ─────────────────────────────────────────

    public function evaluateEarningsReadiness(): FeatureReadiness
    {
        $failures = $this->preflight->failures();

        // At least one payable instructor must exist — enabling earnings
        // on an instructor-less platform is a configuration mistake.
        $payable = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', [InstructorStatus::Approved, InstructorStatus::Active]))
            ->exists();

        if (! $payable) {
            $failures[] = [
                'check' => 'no_payable_instructors',
                'message' => 'No active payable instructor exists yet.',
                'subjects' => [],
            ];
        }

        // The release (and retry) schedules must be registered.
        foreach ([ReleaseInstructorEarnings::class, RetryBlockedLessons::class] as $command) {
            if (! class_exists($command)) {
                $failures[] = [
                    'check' => 'missing_schedule',
                    'message' => sprintf('Required command %s is not registered.', $command),
                    'subjects' => [],
                ];
            }
        }

        return $this->readiness('earnings', $this->settings->earnings_enabled, $failures);
    }

    public function evaluatePeriodicCompensationReadiness(): FeatureReadiness
    {
        $failures = [];

        if (! $this->settings->earnings_enabled) {
            $failures[] = ['check' => 'earnings_disabled', 'message' => 'Earnings must be enabled before periodic compensation.', 'subjects' => []];
        }

        $hasPeriodic = InstructorCompensationAgreement::query()
            ->whereIn('status', [CompensationAgreementStatus::Draft, CompensationAgreementStatus::Scheduled, CompensationAgreementStatus::Active])
            ->where('pay_basis', '!=', CompensationPayBasis::Hourly)
            ->exists();

        if (! $hasPeriodic) {
            $failures[] = ['check' => 'no_periodic_agreements', 'message' => 'No daily, weekly, or monthly agreement exists yet.', 'subjects' => []];
        }

        if (! class_exists(AccruePeriodicCompensation::class)) {
            $failures[] = ['check' => 'missing_schedule', 'message' => 'The periodic accrual command is not registered.', 'subjects' => []];
        }

        if (! $this->indexExists('instructor_compensation_periods', 'icp_agreement_period_unique')) {
            $failures[] = ['check' => 'missing_period_constraint', 'message' => 'The duplicate-period database constraint is missing.', 'subjects' => []];
        }

        if (InstructorCompensationException::query()->open()->exists()) {
            $failures[] = ['check' => 'open_exceptions', 'message' => 'Unresolved compensation exceptions must be cleared first.', 'subjects' => []];
        }

        return $this->readiness('periodic_compensation', $this->settings->periodic_compensation_enabled, $failures);
    }

    public function evaluateWithdrawalReadiness(): FeatureReadiness
    {
        $failures = [];

        if (! $this->settings->earnings_enabled) {
            $failures[] = ['check' => 'earnings_disabled', 'message' => 'Earnings must be enabled before withdrawals.', 'subjects' => []];
        }

        // A releasable-earning path must exist: an active agreement (or an
        // earning already in the pipeline).
        $path = InstructorCompensationAgreement::query()->active()->exists()
            || InstructorEarning::query()->exists();

        if (! $path) {
            $failures[] = ['check' => 'no_earning_path', 'message' => 'No active agreement or existing earning provides a releasable-earning path.', 'subjects' => []];
        }

        if ($this->settings->minimum_withdrawal_minor < 0
            || ($this->settings->maximum_withdrawal_minor !== null && $this->settings->maximum_withdrawal_minor < $this->settings->minimum_withdrawal_minor)) {
            $failures[] = ['check' => 'invalid_limits', 'message' => 'Withdrawal minimum/maximum limits are invalid.', 'subjects' => []];
        }

        // The reservation pipeline must resolve, and the reservation
        // integrity constraints must exist.
        foreach ([InstructorWithdrawalServiceInterface::class, InstructorWithdrawalBalanceServiceInterface::class, InstructorWithdrawalAllocationServiceInterface::class] as $contract) {
            if (! app()->bound($contract)) {
                $failures[] = ['check' => 'missing_service', 'message' => sprintf('%s is not bound.', $contract), 'subjects' => []];
            }
        }

        if (! $this->indexExists('instructor_withdrawal_allocations', 'iwa_request_earning_unique')
            || ! $this->indexExists('instructor_withdrawal_requests', 'iwr_instructor_idempotency_unique')) {
            $failures[] = ['check' => 'missing_withdrawal_constraints', 'message' => 'Withdrawal reservation/idempotency database constraints are missing.', 'subjects' => []];
        }

        if (InstructorCompensationException::query()->open()->exists()) {
            $failures[] = ['check' => 'open_exceptions', 'message' => 'Unresolved compensation exceptions must be cleared first.', 'subjects' => []];
        }

        return $this->readiness('withdrawals', $this->settings->withdrawals_enabled, $failures);
    }

    public function evaluatePayoutExecutionReadiness(): FeatureReadiness
    {
        $failures = [];

        if (! $this->settings->earnings_enabled) {
            $failures[] = ['check' => 'earnings_disabled', 'message' => 'Earnings must be enabled before payout execution.', 'subjects' => []];
        }

        if (! $this->settings->withdrawals_enabled) {
            $failures[] = ['check' => 'withdrawals_disabled', 'message' => 'Withdrawals must be enabled before payout execution.', 'subjects' => []];
        }

        $providerKey = $this->settings->payout_provider;

        if (! $this->providers->has($providerKey)) {
            $failures[] = ['check' => 'provider_not_configured', 'message' => sprintf('Payout provider "%s" is not registered.', $providerKey), 'subjects' => []];
        } else {
            $health = $this->providers->get($providerKey)->healthCheck();

            if (! $health->healthy) {
                $failures[] = ['check' => 'provider_unhealthy', 'message' => $health->safeMessage ?? 'Payout provider health check failed.', 'subjects' => []];
            }
        }

        if (! class_exists(ReconcileInstructorPayouts::class)) {
            $failures[] = ['check' => 'missing_schedule', 'message' => 'The payout reconciliation command is not registered.', 'subjects' => []];
        }

        foreach ([
            ['instructor_payout_attempts', 'ipa_withdrawal_sequence_unique'],
            ['instructor_payout_attempts', 'ipa_provider_idempotency_unique'],
            ['instructor_withdrawal_requests', 'iwr_instructor_idempotency_unique'],
        ] as [$table, $index]) {
            if (! $this->indexExists($table, $index)) {
                $failures[] = ['check' => 'missing_execution_constraint', 'message' => sprintf('Database constraint %s on %s is missing.', $index, $table), 'subjects' => []];
            }
        }

        if (! Schema::hasColumn('instructor_withdrawal_allocations', 'reversed_at')) {
            $failures[] = ['check' => 'missing_allocation_reversal_support', 'message' => 'The withdrawal allocation reversal column is missing.', 'subjects' => []];
        }

        $queueConnection = config('queue.default');

        if (blank($queueConnection) || config("queue.connections.{$queueConnection}") === null) {
            $failures[] = ['check' => 'missing_queue_connection', 'message' => 'No queue connection is configured for the payout execution job.', 'subjects' => []];
        }

        if (InstructorPayoutReconciliationIssue::query()->open()->where('severity', PayoutReconciliationSeverity::Critical)->exists()) {
            $failures[] = ['check' => 'unresolved_critical_reconciliation_issue', 'message' => 'A critical, unresolved reconciliation issue exists.', 'subjects' => []];
        }

        if ($providerKey === RazorpayXInstructorPayoutProvider::KEY) {
            array_push($failures, ...$this->razorpayXReadinessFailures());
        }

        return $this->readiness('payout_execution', $this->settings->payout_execution_enabled, $failures);
    }

    /**
     * RazorpayX-specific preflight (Phase 16B) — only evaluated when the
     * configured payout provider actually resolves to `razorpayx`.
     * Structural only; the provider's own healthCheck() (a genuine
     * network probe) is already covered above via the generic
     * provider_unhealthy check.
     *
     * @return list<array{check: string, message: string, subjects: list<string>}>
     */
    private function razorpayXReadinessFailures(): array
    {
        $failures = [];

        if (! $this->razorpayXSettings->razorpayx_enabled) {
            $failures[] = ['check' => 'razorpayx_disabled', 'message' => 'RazorpayX is not enabled.', 'subjects' => []];
        }

        foreach ($this->razorpayXConfigValidator->issues($this->razorpayXSettings) as $issue) {
            $failures[] = ['check' => 'razorpayx_configuration_invalid', 'message' => $issue, 'subjects' => []];
        }

        if (! $this->razorpayXSettings->razorpayx_contact_provisioning_enabled) {
            $failures[] = ['check' => 'razorpayx_contact_provisioning_disabled', 'message' => 'RazorpayX Contact provisioning is disabled.', 'subjects' => []];
        }

        if (! $this->razorpayXSettings->razorpayx_fund_account_provisioning_enabled) {
            $failures[] = ['check' => 'razorpayx_fund_account_provisioning_disabled', 'message' => 'RazorpayX Fund Account provisioning is disabled.', 'subjects' => []];
        }

        $scope = PayoutRolloutScope::tryFrom($this->settings->payout_rollout_scope) ?? PayoutRolloutScope::Disabled;

        if ($scope === PayoutRolloutScope::Disabled) {
            $failures[] = ['check' => 'razorpayx_rollout_scope_disabled', 'message' => 'The payout rollout scope does not permit any India/INR routing.', 'subjects' => []];
        }

        if (! Schema::hasTable('instructor_payout_destination_provider_links')) {
            $failures[] = ['check' => 'razorpayx_destination_link_table_missing', 'message' => 'The RazorpayX destination provider link table is missing.', 'subjects' => []];
        } elseif (! $this->indexExists('instructor_payout_destination_provider_links', 'ipdpl_method_provider_unique')) {
            $failures[] = ['check' => 'razorpayx_destination_link_constraint_missing', 'message' => 'The RazorpayX destination provider link uniqueness constraint is missing.', 'subjects' => []];
        }

        return $failures;
    }

    // ── Toggles (audited; the only unguarded writers) ────────────────

    public function enableEarnings(User $actor): FeatureReadiness
    {
        $readiness = $this->evaluateEarningsReadiness();

        if (! $readiness->isReady) {
            throw new CompensationException('Earnings cannot be enabled: '.$readiness->summary());
        }

        $this->writeSwitch('earnings_enabled', true, $actor);

        return $readiness;
    }

    public function disableEarnings(User $actor): void
    {
        // Documented rule: periodic compensation cannot outlive the
        // earnings pipeline — both flip off in one guarded save.
        FinancialFeatureToggle::unguarded(function (): void {
            $this->settings->earnings_enabled = false;
            $this->settings->periodic_compensation_enabled = false;
            $this->settings->save();
        });

        $this->audit->logUser($actor, self::LOG_NAME, 'financial_feature_disabled', 'Earnings disabled (periodic compensation auto-disabled with it).', null, ['feature' => 'earnings_enabled']);
    }

    public function enablePeriodicCompensation(User $actor): FeatureReadiness
    {
        $readiness = $this->evaluatePeriodicCompensationReadiness();

        if (! $readiness->isReady) {
            throw new CompensationException('Periodic compensation cannot be enabled: '.$readiness->summary());
        }

        $this->writeSwitch('periodic_compensation_enabled', true, $actor);

        return $readiness;
    }

    public function disablePeriodicCompensation(User $actor): void
    {
        $this->writeSwitch('periodic_compensation_enabled', false, $actor);
    }

    public function enableWithdrawals(User $actor): FeatureReadiness
    {
        $readiness = $this->evaluateWithdrawalReadiness();

        if (! $readiness->isReady) {
            throw new CompensationException('Withdrawals cannot be enabled: '.$readiness->summary());
        }

        $this->writeSwitch('withdrawals_enabled', true, $actor);

        return $readiness;
    }

    public function disableWithdrawals(User $actor): void
    {
        $this->writeSwitch('withdrawals_enabled', false, $actor);
    }

    public function enablePayoutExecution(User $actor): FeatureReadiness
    {
        $readiness = $this->evaluatePayoutExecutionReadiness();

        if (! $readiness->isReady) {
            throw new CompensationException('Payout execution cannot be enabled: '.$readiness->summary());
        }

        $this->writeSwitch('payout_execution_enabled', true, $actor);

        return $readiness;
    }

    public function disablePayoutExecution(User $actor): void
    {
        $this->writeSwitch('payout_execution_enabled', false, $actor);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function writeSwitch(string $switch, bool $value, User $actor): void
    {
        FinancialFeatureToggle::unguarded(function () use ($switch, $value): void {
            $this->settings->{$switch} = $value;
            $this->settings->save();
        });

        $this->audit->logUser($actor, self::LOG_NAME, $value ? 'financial_feature_enabled' : 'financial_feature_disabled', sprintf('%s set to %s.', $switch, $value ? 'true' : 'false'), null, ['feature' => $switch]);
    }

    /** @param list<array{check: string, message: string, subjects: list<string>}> $failures */
    private function readiness(string $feature, bool $currentlyEnabled, array $failures): FeatureReadiness
    {
        return new FeatureReadiness(
            feature: $feature,
            isReady: $failures === [],
            blockingCodes: array_column($failures, 'check'),
            blockingMessages: array_map(
                fn (array $f): string => $f['message'].($f['subjects'] !== [] ? ' — '.implode(', ', array_slice($f['subjects'], 0, 10)).(count($f['subjects']) > 10 ? '…' : '') : ''),
                $failures,
            ),
            affectedSubjects: array_merge(...array_column($failures, 'subjects') ?: [[]]),
            currentlyEnabled: $currentlyEnabled,
            evaluatedAt: CarbonImmutable::now(),
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i): bool => $i['name'] === $index);
    }
}
