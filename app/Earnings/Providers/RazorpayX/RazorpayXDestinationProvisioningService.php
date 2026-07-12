<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Earnings\Contracts\RazorpayXDestinationProvisioningServiceInterface;
use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountRequest;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the RazorpayX Contact → Fund Account provisioning chain for a
 * single payout method. Every provider call happens outside a database
 * transaction (claim → call → finalize), and a call that fails after
 * possibly reaching RazorpayX (timeout, connection error, 5xx) always
 * lands the link in a `*_unknown` state rather than being silently
 * retried with a fresh Contact/Fund Account — see
 * RazorpayXProviderLinkStatus's docblock.
 */
final class RazorpayXDestinationProvisioningService implements RazorpayXDestinationProvisioningServiceInterface
{
    private const string LOG_NAME = 'instructor_payouts';

    public function __construct(
        private readonly RazorpayXPayoutClientInterface $client,
        private readonly RazorpayXPayoutSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function provision(InstructorPayoutMethod $method, User $actor): InstructorPayoutDestinationProviderLink
    {
        $link = $this->findOrCreateLink($method);

        if (in_array($link->status, [RazorpayXProviderLinkStatus::Pending, RazorpayXProviderLinkStatus::ContactUnknown], true)) {
            $link = $this->ensureContact($link, $method, $actor);
        }

        if ($link->status === RazorpayXProviderLinkStatus::ContactReady) {
            $link = $this->ensureFundAccount($link, $method, $actor);
        }

        return $link;
    }

    public function refresh(InstructorPayoutDestinationProviderLink $link, User $actor): InstructorPayoutDestinationProviderLink
    {
        $method = $link->payoutMethod()->firstOrFail();

        return match ($link->status) {
            RazorpayXProviderLinkStatus::Pending, RazorpayXProviderLinkStatus::ContactUnknown => $this->provision($method, $actor),
            RazorpayXProviderLinkStatus::ContactReady, RazorpayXProviderLinkStatus::FundAccountUnknown => $this->ensureFundAccount($link, $method, $actor),
            default => $link,
        };
    }

    public function markStale(InstructorPayoutDestinationProviderLink $link, User $actor, string $reason): InstructorPayoutDestinationProviderLink
    {
        if (trim($reason) === '') {
            throw new RazorpayXProvisioningException('A reason is required to mark a provider link as stale.');
        }

        $link = $this->transition($link, RazorpayXProviderLinkStatus::Stale);

        $this->audit->logUser($actor, self::LOG_NAME, 'razorpayx_link_marked_stale', sprintf('RazorpayX provider link %s marked stale.', $link->id), $link, ['reason' => $reason]);

        return $link;
    }

    public function disable(InstructorPayoutDestinationProviderLink $link, User $actor): InstructorPayoutDestinationProviderLink
    {
        $link = $this->transition($link, RazorpayXProviderLinkStatus::Disabled, [
            'disabled_at' => now(),
            'disabled_by' => $actor->id,
        ]);

        $this->audit->logUser($actor, self::LOG_NAME, 'razorpayx_link_disabled', sprintf('RazorpayX provider link %s disabled.', $link->id), $link);

        return $link;
    }

    // ── Contact ──────────────────────────────────────────────────────────

    private function ensureContact(InstructorPayoutDestinationProviderLink $link, InstructorPayoutMethod $method, User $actor): InstructorPayoutDestinationProviderLink
    {
        if (! $this->settings->razorpayx_contact_provisioning_enabled) {
            throw new RazorpayXProvisioningException('RazorpayX Contact provisioning is currently disabled.');
        }

        // Claim this link for Contact provisioning under a row lock —
        // the sibling-reuse decision happens INSIDE the same lock, so a
        // concurrent caller (same link OR a sibling link for the same
        // instructor) can never race past this point and create a
        // second Contact. A caller who loses the claim gets the link's
        // current (possibly still in-flight) state back — never an
        // error; provisioning is admin-triggered and safely re-checkable.
        $claim = DB::transaction(function () use ($link): array {
            $locked = InstructorPayoutDestinationProviderLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [RazorpayXProviderLinkStatus::Pending, RazorpayXProviderLinkStatus::ContactUnknown], true)) {
                return ['claimed' => false, 'link' => $locked];
            }

            $existing = InstructorPayoutDestinationProviderLink::query()
                ->where('instructor_id', $locked->instructor_id)
                ->whereKeyNot($locked->id)
                ->whereNotNull('provider_contact_id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $locked = $this->finalizeContact($locked, (string) $existing->provider_contact_id, $existing->provider_contact_status);

                return ['claimed' => false, 'link' => $locked];
            }

            $locked = $this->transition($locked, RazorpayXProviderLinkStatus::ContactProvisioning);
            $locked->forceFill(['last_provisioning_attempt_at' => now(), 'provisioning_attempts' => $locked->provisioning_attempts + 1])->save();

            return ['claimed' => true, 'link' => $locked];
        }, attempts: 3);

        if (! $claim['claimed']) {
            return $claim['link'];
        }

        $link = $claim['link'];
        $instructor = $link->instructor()->firstOrFail();

        try {
            // Provider-side reuse guards against a prior attempt that
            // succeeded at RazorpayX but crashed before our DB write.
            $found = $this->client->findContactsByReference($link->provider_contact_reference);

            if ($found !== []) {
                return $this->finalizeContact($link, $found[0]->contactId, $found[0]->status);
            }

            $result = $this->client->createContact(new RazorpayXContactRequest(
                name: $instructor->name,
                referenceId: $link->provider_contact_reference,
                type: 'vendor',
                email: $instructor->email,
            ));

            return $this->finalizeContact($link, $result->contactId, $result->status);
        } catch (Throwable $e) {
            return $this->failProvisioningStep($link, RazorpayXProviderLinkStatus::ContactUnknown, RazorpayXProviderLinkStatus::Failed, $e);
        }
    }

    private function finalizeContact(InstructorPayoutDestinationProviderLink $link, string $contactId, ?string $status): InstructorPayoutDestinationProviderLink
    {
        $link = $this->transition($link, RazorpayXProviderLinkStatus::ContactReady);
        $link->forceFill([
            'provider_contact_id' => $contactId,
            'provider_contact_status' => $status,
            'last_provisioning_error' => null,
        ])->save();

        return $link;
    }

    // ── Fund Account ─────────────────────────────────────────────────────

    private function ensureFundAccount(InstructorPayoutDestinationProviderLink $link, InstructorPayoutMethod $method, User $actor): InstructorPayoutDestinationProviderLink
    {
        if (! $this->settings->razorpayx_fund_account_provisioning_enabled) {
            throw new RazorpayXProvisioningException('RazorpayX Fund Account provisioning is currently disabled.');
        }

        if ($link->provider_contact_id === null) {
            throw new RazorpayXProvisioningException('The RazorpayX Contact must be provisioned before the Fund Account.');
        }

        $details = $this->decryptDetails($method);
        $fingerprint = $this->bankFingerprint($link->provider_contact_id, $details);

        // Same claim-under-lock shape as ensureContact(): the sibling
        // Fund Account reuse decision happens inside the row lock, so
        // two concurrent callers for the same (or a sibling) link can
        // never both create a Fund Account for identical bank details.
        $claim = DB::transaction(function () use ($link, $fingerprint): array {
            $locked = InstructorPayoutDestinationProviderLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['bank_details_fingerprint' => $fingerprint])->save();

            if (! in_array($locked->status, [RazorpayXProviderLinkStatus::ContactReady, RazorpayXProviderLinkStatus::FundAccountUnknown], true)) {
                return ['claimed' => false, 'link' => $locked];
            }

            $existing = InstructorPayoutDestinationProviderLink::query()
                ->where('instructor_id', $locked->instructor_id)
                ->where('provider_contact_id', $locked->provider_contact_id)
                ->where('bank_details_fingerprint', $fingerprint)
                ->whereKeyNot($locked->id)
                ->whereNotNull('provider_fund_account_id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $locked = $this->finalizeFundAccount($locked, (string) $existing->provider_fund_account_id, $existing->provider_fund_account_status);

                return ['claimed' => false, 'link' => $locked];
            }

            $locked = $this->transition($locked, RazorpayXProviderLinkStatus::FundAccountProvisioning);
            $locked->forceFill(['last_provisioning_attempt_at' => now(), 'provisioning_attempts' => $locked->provisioning_attempts + 1])->save();

            return ['claimed' => true, 'link' => $locked];
        }, attempts: 3);

        if (! $claim['claimed']) {
            return $claim['link'];
        }

        $link = $claim['link'];

        try {
            $result = $this->client->createBankFundAccount(new RazorpayXFundAccountRequest(
                contactId: $link->provider_contact_id,
                accountHolderName: $details->accountHolderName,
                accountNumber: (string) $details->accountNumber,
                ifsc: (string) $details->routingNumber,
            ));

            return $this->finalizeFundAccount($link, $result->fundAccountId, $result->status);
        } catch (Throwable $e) {
            return $this->failProvisioningStep($link, RazorpayXProviderLinkStatus::FundAccountUnknown, RazorpayXProviderLinkStatus::Failed, $e);
        } finally {
            // Never let decrypted bank details outlive this method call.
            unset($details);
        }
    }

    private function finalizeFundAccount(InstructorPayoutDestinationProviderLink $link, string $fundAccountId, ?string $status): InstructorPayoutDestinationProviderLink
    {
        $link = $this->transition($link, RazorpayXProviderLinkStatus::Ready);
        $link->forceFill([
            'provider_fund_account_id' => $fundAccountId,
            'provider_fund_account_status' => $status,
            'last_provisioning_error' => null,
        ])->save();

        return $link;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function findOrCreateLink(InstructorPayoutMethod $method): InstructorPayoutDestinationProviderLink
    {
        // A SELECT ... FOR UPDATE on a not-yet-existing row takes a gap
        // lock; two concurrent callers racing to provision the same
        // payout method can legitimately deadlock on the subsequent
        // INSERT (MySQL's documented gap-lock/insert-intention
        // interaction), never a data-integrity problem. Laravel retries
        // the whole closure automatically on a detected deadlock.
        return DB::transaction(function () use ($method): InstructorPayoutDestinationProviderLink {
            $existing = InstructorPayoutDestinationProviderLink::query()
                ->where('payout_method_id', $method->id)
                ->where('provider', 'razorpayx')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $link = new InstructorPayoutDestinationProviderLink([
                'payout_method_id' => $method->id,
                'instructor_id' => $method->instructor_id,
                'provider' => 'razorpayx',
                'provider_contact_reference' => $this->contactReference($method->instructor_id),
                'bank_details_fingerprint' => '',
            ]);
            $link->forceFill(['status' => RazorpayXProviderLinkStatus::Pending])->save();

            return $link;
        }, attempts: 3);
    }

    /** Deterministic per instructor (not per payout method) — one instructor maps to exactly one RazorpayX Contact. ≤40 chars, RazorpayX's reference_id limit. */
    private function contactReference(int $instructorId): string
    {
        return 'ins_'.substr(hash('sha256', 'razorpayx-contact:'.$instructorId), 0, 20);
    }

    private function decryptDetails(InstructorPayoutMethod $method): PayoutMethodDetails
    {
        $data = $method->encrypted_details ?? [];

        return new PayoutMethodDetails(
            accountHolderName: (string) ($data['account_holder_name'] ?? ''),
            bankName: $data['bank_name'] ?? null,
            accountNumber: $data['account_number'] ?? null,
            iban: $data['iban'] ?? null,
            routingType: $data['routing_type'] ?? null,
            routingNumber: $data['routing_number'] ?? null,
            swiftBic: $data['swift_bic'] ?? null,
            branchName: $data['branch_name'] ?? null,
            accountType: $data['account_type'] ?? null,
            beneficiaryAddress: $data['beneficiary_address'] ?? null,
        );
    }

    /** Keyed HMAC of contact + normalized bank identifiers — drift detection only, never reversible to the raw account number. */
    private function bankFingerprint(string $contactId, PayoutMethodDetails $details): string
    {
        $material = implode('|', [
            $contactId,
            PayoutMethodDetails::normalizeIdentifier($details->accountNumber) ?? '',
            PayoutMethodDetails::normalizeIdentifier($details->routingNumber) ?? '',
        ]);

        return hash_hmac('sha256', $material, $this->appKey());
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RazorpayXProvisioningException('Application encryption key is not configured.');
        }

        return $key;
    }

    private function failProvisioningStep(
        InstructorPayoutDestinationProviderLink $link,
        RazorpayXProviderLinkStatus $unknown,
        RazorpayXProviderLinkStatus $permanentFailure,
        Throwable $e,
    ): InstructorPayoutDestinationProviderLink {
        // A validation-shaped 4xx from RazorpayX means the request itself
        // was rejected — it never reached an ambiguous "maybe created"
        // state. Anything else (timeout, connection error, 5xx) might
        // have been accepted provider-side, so it must be reconciled, not
        // silently retried with a new Contact/Fund Account.
        $isPermanent = $e instanceof RazorpayXRequestException
            && $e->httpStatus !== null
            && $e->httpStatus >= 400 && $e->httpStatus < 500;

        $next = $isPermanent ? $permanentFailure : $unknown;

        $link = $this->transition($link, $next);
        $link->forceFill(['last_provisioning_error' => $this->safeErrorMessage($e)])->save();

        return $link;
    }

    private function safeErrorMessage(Throwable $e): string
    {
        return $e instanceof RazorpayXRequestException
            ? $e->getMessage()
            : 'RazorpayX could not be reached.';
    }

    /** @param array<string, mixed> $extra */
    private function transition(InstructorPayoutDestinationProviderLink $link, RazorpayXProviderLinkStatus $next, array $extra = []): InstructorPayoutDestinationProviderLink
    {
        if (! $link->status->canTransitionTo($next)) {
            throw new RazorpayXProvisioningException(sprintf('Cannot move a RazorpayX provider link from %s to %s.', $link->status->label(), $next->label()));
        }

        $link->forceFill([...$extra, 'status' => $next])->save();

        return $link;
    }
}
