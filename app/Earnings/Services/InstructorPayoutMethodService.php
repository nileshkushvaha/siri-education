<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\PayoutMethodFingerprintServiceInterface;
use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Events\InstructorPayoutMethodDisabled;
use App\Earnings\Events\InstructorPayoutMethodRejected;
use App\Earnings\Events\InstructorPayoutMethodSubmitted;
use App\Earnings\Events\InstructorPayoutMethodVerified;
use App\Earnings\Exceptions\InvalidPayoutMethodTransitionException;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\Country;
use App\Models\Currency;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the payout-method lifecycle: draft → pending_verification →
 * verified/rejected, defaulting, and disabling. Sensitive details enter
 * only through PayoutMethodDetails DTOs, are stored encrypted, and are
 * decrypted exclusively by viewSensitiveDetails() (permission-gated,
 * access-logged). Verified details are immutable — corrections happen
 * on draft/rejected methods, replacements otherwise. No money moves
 * here, ever.
 */
final class InstructorPayoutMethodService implements InstructorPayoutMethodServiceInterface
{
    private const string LOG_NAME = 'instructor_payouts';

    public function __construct(
        private readonly PayoutMethodFingerprintServiceInterface $fingerprints,
        private readonly InstructorPayoutEligibility $eligibility,
        private readonly AuditTrailService $audit,
    ) {}

    public function createDraft(User $instructor, PayoutMethodType $type, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): InstructorPayoutMethod
    {
        if (($reason = $this->eligibility->reasonForIneligibility($instructor)) !== null) {
            throw new PayoutMethodException($reason);
        }

        if (! in_array($type, PayoutMethodType::enabledForInstructors(), strict: true)) {
            throw new PayoutMethodException('This payout method type is not available.');
        }

        $currencyCode = $this->validateContext($countryId, $currencyCode);
        $this->validateDetails($details);

        $fingerprint = $this->fingerprints->generate($type, $countryId, $currencyCode, $details);

        $method = DB::transaction(function () use ($instructor, $type, $countryId, $currencyCode, $details, $fingerprint): InstructorPayoutMethod {
            $this->assertNoDuplicate($instructor->id, $fingerprint);

            $method = new InstructorPayoutMethod([
                'instructor_id' => $instructor->id,
                'type' => $type,
                'country_id' => $countryId,
                'currency_id' => Currency::query()->where('code', $currencyCode)->value('id'),
                'currency_code' => $currencyCode,
                'display_label' => $this->displayLabel($type, $details),
                'masked_identifier' => $this->maskedIdentifier($details),
                'fingerprint' => $fingerprint,
                'encrypted_details' => $details->toArray(),
            ]);

            // Lifecycle fields are not mass assignable by design.
            $method->forceFill(['status' => PayoutMethodStatus::Draft, 'is_default' => false])->save();

            return $method;
        });

        $this->audit->logUser($instructor, self::LOG_NAME, 'payout_method_created', sprintf('Payout method %s (%s) drafted.', $method->id, $method->display_label), $method);

        return $method;
    }

    public function updateDraft(InstructorPayoutMethod $method, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): InstructorPayoutMethod
    {
        if (! $method->status->isEditable()) {
            throw new PayoutMethodException('Only draft or rejected payout methods can be edited.');
        }

        $currencyCode = $this->validateContext($countryId, $currencyCode);
        $this->validateDetails($details);

        $fingerprint = $this->fingerprints->generate($method->type, $countryId, $currencyCode, $details);

        $method = DB::transaction(function () use ($method, $countryId, $currencyCode, $details, $fingerprint): InstructorPayoutMethod {
            $this->assertNoDuplicate($method->instructor_id, $fingerprint, exceptId: $method->id);

            // A corrected rejected method returns to draft — resubmission
            // is an explicit, separate step.
            $method->fill([
                'country_id' => $countryId,
                'currency_id' => Currency::query()->where('code', $currencyCode)->value('id'),
                'currency_code' => $currencyCode,
                'display_label' => $this->displayLabel($method->type, $details),
                'masked_identifier' => $this->maskedIdentifier($details),
                'fingerprint' => $fingerprint,
                'encrypted_details' => $details->toArray(),
            ])->forceFill([
                'status' => PayoutMethodStatus::Draft,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ])->save();

            return $method;
        });

        $this->audit->logUser($method->instructor, self::LOG_NAME, 'payout_method_updated', sprintf('Payout method %s details replaced (draft).', $method->id), $method);

        return $method;
    }

    public function submitForVerification(InstructorPayoutMethod $method, User $actor): InstructorPayoutMethod
    {
        $method = $this->transition($method, PayoutMethodStatus::PendingVerification, [
            'submitted_at' => now(),
        ]);

        $this->audit->logUser($actor, self::LOG_NAME, 'payout_method_submitted', sprintf('Payout method %s submitted for verification.', $method->id), $method);

        InstructorPayoutMethodSubmitted::dispatch($method);

        return $method;
    }

    public function verify(InstructorPayoutMethod $method, User $admin): InstructorPayoutMethod
    {
        $method = $this->transition($method, PayoutMethodStatus::Verified, [
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $this->audit->logUser($admin, self::LOG_NAME, 'payout_method_verified', sprintf('Payout method %s verified.', $method->id), $method);

        InstructorPayoutMethodVerified::dispatch($method);

        return $method;
    }

    public function reject(InstructorPayoutMethod $method, User $admin, string $reason): InstructorPayoutMethod
    {
        if (trim($reason) === '') {
            throw new PayoutMethodException('A rejection reason is required.');
        }

        $method = $this->transition($method, PayoutMethodStatus::Rejected, [
            'rejected_at' => now(),
            'rejected_by' => $admin->id,
            'rejection_reason' => $reason,
        ]);

        $this->audit->logUser($admin, self::LOG_NAME, 'payout_method_rejected', sprintf('Payout method %s rejected.', $method->id), $method, ['reason' => $reason]);

        InstructorPayoutMethodRejected::dispatch($method);

        return $method;
    }

    public function setDefault(InstructorPayoutMethod $method, User $actor): InstructorPayoutMethod
    {
        $method = DB::transaction(function () use ($method): InstructorPayoutMethod {
            // Canonical lock order: the instructor's user row first —
            // concurrent default switches (even on different methods)
            // serialize here, then the method rows.
            User::query()->whereKey($method->instructor_id)->lockForUpdate()->first();

            $method = InstructorPayoutMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if ($method->status !== PayoutMethodStatus::Verified) {
                throw new PayoutMethodException('Only a verified payout method can be the default.');
            }

            InstructorPayoutMethod::query()
                ->where('instructor_id', $method->instructor_id)
                ->whereKeyNot($method->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->get()
                ->each(fn (InstructorPayoutMethod $old) => $old->forceFill(['is_default' => false])->save());

            $method->forceFill(['is_default' => true])->save();

            return $method;
        });

        $this->audit->logUser($actor, self::LOG_NAME, 'payout_method_set_default', sprintf('Payout method %s set as default.', $method->id), $method);

        return $method;
    }

    public function disable(InstructorPayoutMethod $method, User $actor, ?string $reason = null): InstructorPayoutMethod
    {
        $method = DB::transaction(function () use ($method, $actor): InstructorPayoutMethod {
            // Canonical lock order: owner row first — disabling serializes
            // with withdrawal creation, so the active-withdrawal check
            // below can never race a request being created concurrently.
            User::query()->whereKey($method->instructor_id)->lockForUpdate()->first();

            $method = InstructorPayoutMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            $activeWithdrawals = InstructorWithdrawalRequest::query()
                ->where('payout_method_id', $method->id)
                ->active()
                ->exists();

            if ($activeWithdrawals) {
                throw new PayoutMethodException('This payout method is used by an active withdrawal request and cannot be disabled.');
            }

            if (! $method->status->canTransitionTo(PayoutMethodStatus::Disabled)) {
                throw InvalidPayoutMethodTransitionException::between($method->status, PayoutMethodStatus::Disabled);
            }

            $method->forceFill([
                'status' => PayoutMethodStatus::Disabled,
                'is_default' => false,
                'disabled_at' => now(),
                'disabled_by' => $actor->id,
            ])->save();

            return $method;
        });

        $this->audit->logUser($actor, self::LOG_NAME, 'payout_method_disabled', sprintf('Payout method %s disabled.', $method->id), $method, array_filter(['reason' => $reason]));

        InstructorPayoutMethodDisabled::dispatch($method);

        return $method;
    }

    public function viewSensitiveDetails(InstructorPayoutMethod $method, User $admin): array
    {
        if (! $admin->can('viewSensitive', $method)) {
            throw new PayoutMethodException('You are not allowed to view sensitive payout details.');
        }

        // Decrypt before logging the access: an undecryptable payload
        // (corruption, APP_KEY loss) must fail with a safe domain message
        // that carries neither ciphertext nor decryption internals.
        try {
            $details = $method->encrypted_details ?? [];
        } catch (DecryptException) {
            throw new PayoutMethodException('The stored payout details cannot be decrypted. Verify the application encryption key and restore from a key backup if it was rotated.');
        }

        // Log the access, never the values.
        $this->audit->logUser($admin, self::LOG_NAME, 'payout_method_sensitive_viewed', sprintf('Sensitive details of payout method %s viewed for verification.', $method->id), $method);

        return $details;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function validateContext(?int $countryId, string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));

        if (! Currency::query()->active()->where('code', $currencyCode)->exists()) {
            throw new PayoutMethodException('This currency is not supported.');
        }

        if ($countryId !== null && ! Country::query()->active()->whereKey($countryId)->exists()) {
            throw new PayoutMethodException('This country is not supported.');
        }

        return $currencyCode;
    }

    /** Bank-transfer rules: holder name plus account number (with routing) or IBAN. */
    private function validateDetails(PayoutMethodDetails $details): void
    {
        if (trim($details->accountHolderName) === '') {
            throw new PayoutMethodException('The account holder name is required.');
        }

        $account = PayoutMethodDetails::normalizeIdentifier($details->accountNumber);
        $iban = PayoutMethodDetails::normalizeIdentifier($details->iban);

        if ($account === null && $iban === null) {
            throw new PayoutMethodException('An account number or IBAN is required.');
        }

        if ($account !== null && PayoutMethodDetails::normalizeIdentifier($details->routingNumber) === null) {
            throw new PayoutMethodException('Routing information (e.g. IFSC) is required with an account number.');
        }
    }

    private function assertNoDuplicate(int $instructorId, string $fingerprint, ?string $exceptId = null): void
    {
        $duplicate = InstructorPayoutMethod::query()
            ->where('instructor_id', $instructorId)
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', PayoutMethodStatus::Disabled)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw new PayoutMethodException('You already have a payout method with these details.');
        }
    }

    private function displayLabel(PayoutMethodType $type, PayoutMethodDetails $details): string
    {
        return sprintf('%s ending in %s', $type->label(), $details->lastFour());
    }

    private function maskedIdentifier(PayoutMethodDetails $details): string
    {
        $label = PayoutMethodDetails::normalizeIdentifier($details->accountNumber) !== null ? 'Account' : 'IBAN';

        return sprintf('%s ending in %s', $label, $details->lastFour());
    }

    /** @param array<string, mixed> $extra */
    private function transition(InstructorPayoutMethod $method, PayoutMethodStatus $next, array $extra = []): InstructorPayoutMethod
    {
        if (! $method->status->canTransitionTo($next)) {
            throw InvalidPayoutMethodTransitionException::between($method->status, $next);
        }

        $method->forceFill([...$extra, 'status' => $next])->save();

        return $method;
    }
}
