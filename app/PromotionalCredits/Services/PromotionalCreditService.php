<?php

declare(strict_types=1);

namespace App\PromotionalCredits\Services;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Enums\StudentStatus;
use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use App\PromotionalCredits\DTOs\PromotionalCreditCampaignData;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use App\PromotionalCredits\Events\PromotionalCreditIssued;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\Services\AuditTrailService;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single authoritative writer of promotional-credit campaigns and
 * issuances (GAP-041, SRS §13.20/§16.17-§16.19). Every wallet mutation
 * routes through the existing WalletLedgerService/WalletService —
 * this class never touches a *_minor column or writes a ledger row
 * itself. Mirrors ReferralCampaignService's campaign-lifecycle shape
 * and ReferralRewardService's credit-then-record ordering.
 */
final class PromotionalCreditService
{
    private const string LOG_NAME = 'promotional_credits';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $ledger,
        private readonly AuditTrailService $auditTrail,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    // ── Campaign management ─────────────────────────────────────────────

    public function createCampaign(PromotionalCreditCampaignData $data, User $admin): PromotionalCreditCampaign
    {
        $this->assertCanManageCampaigns($admin);
        $this->assertValidCampaignData($data);

        $campaign = DB::transaction(function () use ($data, $admin): PromotionalCreditCampaign {
            return PromotionalCreditCampaign::query()->create([
                ...$this->campaignAttributes($data),
                'status' => PromotionalCreditCampaignStatus::Draft,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            'campaign_created',
            sprintf('Promotional credit campaign "%s" created as draft.', $campaign->name),
            $campaign,
            $this->safeCampaignMeta($campaign),
        );

        return $campaign;
    }

    public function updateCampaign(PromotionalCreditCampaign $campaign, PromotionalCreditCampaignData $data, User $admin): PromotionalCreditCampaign
    {
        $this->assertCanManageCampaigns($admin);
        $this->assertValidCampaignData($data);

        [$campaign, $before] = DB::transaction(function () use ($campaign, $data, $admin): array {
            $campaign = $this->lockedCampaign($campaign);

            if (! $campaign->status->isEditable()) {
                throw new PromotionalCreditException(sprintf(
                    'A %s campaign cannot be edited — pause it first, or archive and create a successor.',
                    $campaign->status->value,
                ));
            }

            $this->assertRulesStillMutable($campaign, $data);

            $before = $this->safeCampaignMeta($campaign);

            $campaign->fill($this->campaignAttributes($data));
            $campaign->updated_by = $admin->id;
            $campaign->save();

            return [$campaign, $before];
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            'campaign_rules_updated',
            sprintf('Promotional credit campaign "%s" rules updated.', $campaign->name),
            $campaign,
            ['before' => $before, 'after' => $this->safeCampaignMeta($campaign->refresh())],
        );

        return $campaign;
    }

    public function activateCampaign(PromotionalCreditCampaign $campaign, User $admin, string $reason): PromotionalCreditCampaign
    {
        return $this->transitionCampaign($campaign, PromotionalCreditCampaignStatus::Active, $admin, $reason, 'campaign_activated', function (PromotionalCreditCampaign $locked): void {
            if ($locked->ends_at->getTimestamp() <= now()->getTimestamp()) {
                throw new PromotionalCreditException('An expired campaign window cannot be activated — extend ends_at first.');
            }
        });
    }

    public function pauseCampaign(PromotionalCreditCampaign $campaign, User $admin, string $reason): PromotionalCreditCampaign
    {
        return $this->transitionCampaign($campaign, PromotionalCreditCampaignStatus::Paused, $admin, $reason, 'campaign_paused');
    }

    public function resumeCampaign(PromotionalCreditCampaign $campaign, User $admin, string $reason): PromotionalCreditCampaign
    {
        if ($campaign->status !== PromotionalCreditCampaignStatus::Paused) {
            throw new PromotionalCreditException('Only a paused campaign can be resumed.');
        }

        return $this->transitionCampaign($campaign, PromotionalCreditCampaignStatus::Active, $admin, $reason, 'campaign_resumed', function (PromotionalCreditCampaign $locked): void {
            if ($locked->ends_at->getTimestamp() <= now()->getTimestamp()) {
                throw new PromotionalCreditException('An expired campaign window cannot be resumed — extend ends_at first.');
            }
        });
    }

    public function completeCampaign(PromotionalCreditCampaign $campaign, User $admin, string $reason): PromotionalCreditCampaign
    {
        return $this->transitionCampaign($campaign, PromotionalCreditCampaignStatus::Completed, $admin, $reason, 'campaign_completed');
    }

    public function archiveCampaign(PromotionalCreditCampaign $campaign, User $admin, string $reason): PromotionalCreditCampaign
    {
        return $this->transitionCampaign($campaign, PromotionalCreditCampaignStatus::Archived, $admin, $reason, 'campaign_archived');
    }

    // ── Issuance ─────────────────────────────────────────────────────────

    /** SRS §16.17 "Campaign Credit" — amount/currency always come from the campaign, never admin-chosen. */
    public function issueCampaignCredit(PromotionalCreditCampaign $campaign, User $student, User $admin, string $reason, string $idempotencyKey): PromotionalCreditIssuance
    {
        return $this->issue($student, $admin, $campaign->amount_minor, $campaign->currency_code, $reason, $campaign, $idempotencyKey);
    }

    /** SRS §16.18 "Manual Goodwill Credit" — admin-chosen amount/currency, always with a mandatory reason. */
    public function issueManualCredit(User $student, User $admin, int $amountMinor, string $currencyCode, string $reason, string $idempotencyKey): PromotionalCreditIssuance
    {
        return $this->issue($student, $admin, $amountMinor, $currencyCode, $reason, null, $idempotencyKey);
    }

    /** SRS §16.34/requirement #9 — bounded aggregate only, never a raw collection counted in PHP. */
    public function campaignUsageSummary(PromotionalCreditCampaign $campaign): array
    {
        $issued = PromotionalCreditIssuance::query()->where('campaign_id', $campaign->id);

        return [
            'issued_count' => (clone $issued)->count(),
            'issued_amount_minor' => (int) (clone $issued)->sum('amount_minor'),
            'budget_minor' => $campaign->total_budget_minor,
            'budget_remaining_minor' => $campaign->total_budget_minor !== null
                ? max(0, $campaign->total_budget_minor - (int) (clone $issued)->sum('amount_minor'))
                : null,
            'per_student_limit' => $campaign->per_student_limit,
        ];
    }

    // ── Internals: issuance ──────────────────────────────────────────────

    private function issue(
        User $student,
        User $admin,
        int $amountMinor,
        string $currencyCode,
        string $reason,
        ?PromotionalCreditCampaign $campaign,
        string $idempotencyKey,
    ): PromotionalCreditIssuance {
        $this->assertCanIssue($admin);
        $this->assertGloballyEnabled($student);
        $this->assertPositiveAmount($amountMinor);
        $this->assertReason($reason);
        $this->assertEligibleStudent($student);

        $existing = PromotionalCreditIssuance::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($student, $admin, $amountMinor, $currencyCode, $reason, $campaign, $idempotencyKey): PromotionalCreditIssuance {
            $existing = PromotionalCreditIssuance::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($campaign !== null) {
                $campaign = $this->lockedCampaign($campaign);
                $this->assertCampaignIssuable($campaign, $currencyCode);
                $this->assertPerStudentLimit($campaign, $student);
                $this->assertBudget($campaign, $amountMinor);
            }

            // The student's own default-currency wallet — never a
            // second wallet minted in the campaign's currency just to
            // force the credit through (requirement #5 "wallet/campaign
            // currency match"). Actor = the student themselves (matching
            // ReferralRewardService::creditReward()'s identical
            // getOrCreateWalletForExistingObligation($referrer, null,
            // $referrer) pattern) — the admin's authority to trigger this
            // at all is already gated by assertCanIssue() above;
            // WalletService's own actor-authorization check exists to
            // answer a different question ("may $actor access $user's
            // wallet") and must not additionally require the broad,
            // deliberately-ungranted Manage:Wallet permission just to
            // issue a promotional credit.
            $wallet = $this->wallets->getOrCreateWallet($student, null, $student);

            if ($wallet->currency_code !== $currencyCode) {
                throw new PromotionalCreditException(sprintf(
                    'Student wallet currency %s does not match the credit currency %s — money is never converted.',
                    $wallet->currency_code,
                    $currencyCode,
                ));
            }

            $issuanceId = (string) Str::uuid();

            try {
                $entry = $this->ledger->credit(
                    $wallet,
                    $amountMinor,
                    WalletLedgerEntryType::PromotionalCredit,
                    $admin,
                    idempotencyKey: $idempotencyKey,
                    description: $campaign !== null
                        ? sprintf('Promotional credit: %s', $campaign->name)
                        : sprintf('Manual promotional credit: %s', $reason),
                    sourceType: PromotionalCreditIssuance::class,
                    sourceId: $issuanceId,
                    metadata: array_filter(['campaign_id' => $campaign?->id]),
                );
            } catch (WalletException $e) {
                throw new PromotionalCreditException(sprintf('Wallet credit failed: %s', $e->getMessage()), previous: $e);
            }

            // A duplicate idempotency hit at the ledger layer means a
            // prior attempt already produced the issuance row too —
            // reconcile to it rather than create a second one.
            $existing = PromotionalCreditIssuance::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $issuance = PromotionalCreditIssuance::query()->create([
                'id' => $issuanceId,
                'campaign_id' => $campaign?->id,
                'student_id' => $student->id,
                'wallet_ledger_entry_id' => $entry->id,
                'amount_minor' => $amountMinor,
                'currency_code' => $currencyCode,
                'issuance_type' => $campaign !== null ? PromotionalCreditIssuanceType::Campaign : PromotionalCreditIssuanceType::Manual,
                'issued_by' => $admin->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'issued_at' => now(),
            ]);

            $this->auditTrail->logUser(
                $admin,
                self::LOG_NAME,
                'credit_issued',
                sprintf('Promotional credit issued to student #%d (%d %s minor units).', $student->id, $amountMinor, $currencyCode),
                $issuance,
                array_filter(['campaign_id' => $campaign?->id, 'reason' => $reason, 'wallet_ledger_entry_id' => $entry->id]),
            );

            PromotionalCreditIssued::dispatch($issuance->id, $student->id);

            return $issuance;
        });
    }

    // ── Internals: assertions ────────────────────────────────────────────

    private function assertCanIssue(User $admin): void
    {
        if (! $admin->can('IssuePromotionalCredit')) {
            throw new AuthorizationException;
        }
    }

    private function assertCanManageCampaigns(User $admin): void
    {
        if (! $admin->can('ManagePromotionalCreditCampaigns')) {
            throw new AuthorizationException;
        }
    }

    private function assertGloballyEnabled(User $student): void
    {
        $country = $this->countryResolver->forStudent($student);

        if (! $this->countryFeatures->isEnabled(CountryFeature::PromotionalCredits, $country)) {
            throw new PromotionalCreditException('Promotional credits are not currently available for this student.');
        }
    }

    private function assertPositiveAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new PromotionalCreditException('The credit amount must be positive.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new PromotionalCreditException('A promotional credit requires a reason.');
        }
    }

    /** SRS §16.11-style eligibility, reused for promotional credits: an active, verified student. */
    private function assertEligibleStudent(User $student): void
    {
        if (! $student->hasRole('student')) {
            throw new PromotionalCreditException('Promotional credits may only be issued to students.');
        }

        if ($student->status !== User::STATUS_ACTIVE) {
            throw new PromotionalCreditException('The student account is not active.');
        }

        if (! $student->hasVerifiedEmail()) {
            throw new PromotionalCreditException('The student has not verified their email.');
        }

        if ($student->profile?->student_status !== StudentStatus::Active) {
            throw new PromotionalCreditException('The student is not in an active student-lifecycle state.');
        }
    }

    private function assertCampaignIssuable(PromotionalCreditCampaign $campaign, string $currencyCode): void
    {
        if ($campaign->status !== PromotionalCreditCampaignStatus::Active) {
            throw new PromotionalCreditException(sprintf('Campaign "%s" is %s, not active.', $campaign->name, $campaign->status->value));
        }

        $now = now();

        if ($now->lt($campaign->starts_at) || $now->gte($campaign->ends_at)) {
            throw new PromotionalCreditException(sprintf('Campaign "%s" is outside its active date window.', $campaign->name));
        }

        if ($campaign->currency_code !== $currencyCode) {
            throw new PromotionalCreditException(sprintf(
                'Campaign "%s" currency %s does not match credit currency %s.',
                $campaign->name,
                $campaign->currency_code,
                $currencyCode,
            ));
        }
    }

    private function assertPerStudentLimit(PromotionalCreditCampaign $campaign, User $student): void
    {
        $count = PromotionalCreditIssuance::query()
            ->where('campaign_id', $campaign->id)
            ->where('student_id', $student->id)
            ->count();

        if ($count >= $campaign->per_student_limit) {
            throw new PromotionalCreditException(sprintf(
                'Student #%d has already reached campaign "%s"\'s per-student limit of %d award(s).',
                $student->id,
                $campaign->name,
                $campaign->per_student_limit,
            ));
        }
    }

    private function assertBudget(PromotionalCreditCampaign $campaign, int $amountMinor): void
    {
        if ($campaign->total_budget_minor === null) {
            return;
        }

        $issued = (int) PromotionalCreditIssuance::query()->where('campaign_id', $campaign->id)->sum('amount_minor');

        if ($issued + $amountMinor > $campaign->total_budget_minor) {
            throw new PromotionalCreditException(sprintf(
                'Campaign "%s" budget of %d minor units would be exceeded (already issued %d, requested %d).',
                $campaign->name,
                $campaign->total_budget_minor,
                $issued,
                $amountMinor,
            ));
        }
    }

    // ── Internals: campaign lifecycle plumbing ───────────────────────────

    /** @param  callable(PromotionalCreditCampaign): void|null  $guard */
    private function transitionCampaign(
        PromotionalCreditCampaign $campaign,
        PromotionalCreditCampaignStatus $target,
        User $admin,
        string $reason,
        string $auditEvent,
        ?callable $guard = null,
    ): PromotionalCreditCampaign {
        $this->assertCanManageCampaigns($admin);

        if (trim($reason) === '') {
            throw new PromotionalCreditException('A campaign status change requires a reason.');
        }

        $campaign = DB::transaction(function () use ($campaign, $target, $admin, $guard): PromotionalCreditCampaign {
            $campaign = $this->lockedCampaign($campaign);

            if (! $campaign->status->canTransitionTo($target)) {
                throw new PromotionalCreditException(sprintf(
                    'A %s campaign cannot become %s.',
                    $campaign->status->value,
                    $target->value,
                ));
            }

            if ($guard !== null) {
                $guard($campaign);
            }

            $campaign->forceFill([
                'status' => $target,
                'updated_by' => $admin->id,
            ])->save();

            return $campaign;
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            $auditEvent,
            sprintf('Promotional credit campaign "%s" is now %s.', $campaign->name, $campaign->status->value),
            $campaign,
            [...$this->safeCampaignMeta($campaign), 'reason' => $reason],
        );

        return $campaign;
    }

    private function assertValidCampaignData(PromotionalCreditCampaignData $data): void
    {
        if (trim($data->name) === '') {
            throw new PromotionalCreditException('A campaign requires a name.');
        }

        if ($data->startsAt >= $data->endsAt) {
            throw new PromotionalCreditException('The campaign start must be before its end.');
        }

        if ($data->amountMinor <= 0) {
            throw new PromotionalCreditException('The credit amount must be positive.');
        }

        if (Currency::query()->active()->where('code', $data->currencyCode)->doesntExist()) {
            throw new PromotionalCreditException(sprintf('Currency "%s" is not active.', $data->currencyCode));
        }

        if ($data->perStudentLimit < 1) {
            throw new PromotionalCreditException('The per-student limit must be at least 1.');
        }

        if ($data->totalBudgetMinor !== null && $data->totalBudgetMinor <= 0) {
            throw new PromotionalCreditException('The campaign budget, when set, must be positive.');
        }
    }

    /**
     * Phase 33 (mirrors ReferralCampaignService's identical rule): once
     * any issuance references this campaign, its money-affecting rules
     * are frozen forever — issuances snapshot the rules they were
     * issued under. Name, description, and terms remain editable.
     */
    private function assertRulesStillMutable(PromotionalCreditCampaign $campaign, PromotionalCreditCampaignData $data): void
    {
        if (PromotionalCreditIssuance::query()->where('campaign_id', $campaign->id)->doesntExist()) {
            return;
        }

        $changed = $campaign->amount_minor !== $data->amountMinor
            || $campaign->currency_code !== $data->currencyCode
            || $campaign->per_student_limit !== $data->perStudentLimit
            || $campaign->total_budget_minor !== $data->totalBudgetMinor
            || $campaign->starts_at->notEqualTo($data->startsAt)
            || $campaign->ends_at->notEqualTo($data->endsAt);

        if ($changed) {
            throw new PromotionalCreditException(
                'This campaign has already issued credits — its amount, currency, limits, and window are permanently frozen. Only the name, description, and terms may change; create a successor campaign for new rules.',
            );
        }
    }

    private function campaignAttributes(PromotionalCreditCampaignData $data): array
    {
        $currency = Currency::query()->where('code', $data->currencyCode)->firstOrFail();

        return [
            'name' => $data->name,
            'description' => $data->description,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
            'amount_minor' => $data->amountMinor,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'per_student_limit' => $data->perStudentLimit,
            'total_budget_minor' => $data->totalBudgetMinor,
            'terms' => $data->terms,
        ];
    }

    /** Rule snapshot for audit rows. */
    private function safeCampaignMeta(PromotionalCreditCampaign $campaign): array
    {
        return [
            'status' => $campaign->status->value,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at->toIso8601String(),
            'amount_minor' => $campaign->amount_minor,
            'currency_code' => $campaign->currency_code,
            'per_student_limit' => $campaign->per_student_limit,
            'total_budget_minor' => $campaign->total_budget_minor,
        ];
    }

    private function lockedCampaign(PromotionalCreditCampaign $campaign): PromotionalCreditCampaign
    {
        return PromotionalCreditCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }
}
