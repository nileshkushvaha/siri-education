<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Enums\StudentStatus;
use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralAttributionSource;
use App\Referral\Events\ReferralAttributed;
use App\Referral\Exceptions\ReferralException;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the permanent referrer relationship during registration —
 * the ONLY moment attribution can ever be recorded (SRS 16.10). By
 * design this never throws to its caller: an invalid code must never
 * block a registration, and no failure reason is ever surfaced that
 * could turn the optional field into an account-enumeration oracle.
 *
 * The unique index on referred_student_id is the final single-referrer
 * guard; the DB CHECK is the final self-referral guard. Everything
 * here is the polite, silent layer in front of those constraints.
 */
final class ReferralAttributionService implements ReferralAttributionServiceInterface
{
    public function __construct(
        private readonly ReferralCodeServiceInterface $codes,
        private readonly AuditTrailService $auditTrail,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function attributeFromRegistration(User $referredStudent, ?string $rawCode, ?string $source = null): ?ReferralAttribution
    {
        if ($rawCode === null || trim($rawCode) === '') {
            return null;
        }

        try {
            return $this->attempt($referredStudent, $rawCode, $source);
        } catch (Throwable $e) {
            // Attribution is strictly best-effort — registration has
            // already succeeded and must complete normally.
            Log::warning('Referral attribution failed silently during registration.', [
                'referred_student_id' => $referredStudent->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function attempt(User $referredStudent, string $rawCode, ?string $source): ?ReferralAttribution
    {
        if (! $this->countryFeatures->isEnabled(CountryFeature::Referrals, $this->countryResolver->forStudent($referredStudent))) {
            return null;
        }

        if (! $referredStudent->hasRole('student')) {
            return null;
        }

        // The original attribution is permanent — later attempts
        // (duplicate submissions, replays) are ignored, never reassigned.
        if (ReferralAttribution::query()->where('referred_student_id', $referredStudent->id)->exists()) {
            return null;
        }

        $code = $this->codes->findActiveByCode($rawCode);

        if ($code === null) {
            return null;
        }

        $referrer = $code->user;

        if ($referrer === null || ! $this->isEligibleReferrer($referrer, $referredStudent)) {
            return null;
        }

        $attributionSource = $source === ReferralAttributionSource::Link->value
            ? ReferralAttributionSource::Link
            : ReferralAttributionSource::Manual;

        try {
            return DB::transaction(function () use ($referredStudent, $code, $referrer, $attributionSource): ?ReferralAttribution {
                // Re-check the code under lock: an admin disable that
                // commits between the lookup and this insert must win —
                // a disabled code never gains a new attribution.
                $lockedCode = ReferralCode::query()->whereKey($code->id)->lockForUpdate()->first();

                if ($lockedCode === null || ! $lockedCode->isActive()) {
                    return null;
                }

                $attribution = ReferralAttribution::query()->create([
                    'referrer_id' => $referrer->id,
                    'referred_student_id' => $referredStudent->id,
                    'referral_code_id' => $code->id,
                    'source' => $attributionSource,
                    'attributed_at' => now(),
                ]);

                $this->auditTrail->logUser(
                    $referredStudent,
                    'referral_attributions',
                    'attributed',
                    sprintf('Student #%d attributed to referrer #%d via referral code.', $referredStudent->id, $referrer->id),
                    $attribution,
                    [
                        'referrer_id' => $referrer->id,
                        'referral_code_id' => $code->id,
                        'source' => $attributionSource->value,
                    ],
                );

                ReferralAttributed::dispatch($attribution->id, $referrer->id, $referredStudent->id);

                return $attribution;
            });
        } catch (QueryException) {
            // Concurrent duplicate lost the race to the unique index —
            // the winning attribution stands; report nothing new.
            return null;
        }
    }

    public function correctAttribution(ReferralAttribution $attribution, User $newReferrer, User $admin, string $reason): ReferralAttribution
    {
        if (! $admin->can('CorrectReferralAttribution')) {
            throw new AuthorizationException;
        }

        if (trim($reason) === '') {
            throw new ReferralException('An attribution correction requires a reason.');
        }

        if ($admin->id === $newReferrer->id) {
            throw new ReferralException('An administrator can never assign themselves as the referrer.');
        }

        if ($admin->id === $attribution->referred_student_id) {
            throw new ReferralException('An administrator can never choose their own referrer.');
        }

        return DB::transaction(function () use ($attribution, $newReferrer, $admin, $reason): ReferralAttribution {
            // The same lock every reward evaluation takes — correction
            // and reward creation serialize on the attribution row.
            $attribution = ReferralAttribution::query()->whereKey($attribution->id)->lockForUpdate()->firstOrFail();

            // The safe rule: once any reward row exists, ownership is
            // financial history and is never re-assigned (the SRS
            // requires audited correction, not post-reward migration).
            if (ReferralReward::query()->where('attribution_id', $attribution->id)->exists()) {
                throw new ReferralException(
                    'This attribution has already generated rewards — it can no longer be corrected. Reward ownership is permanent financial history.',
                );
            }

            $referred = $attribution->referredStudent;

            if ($referred === null || ! $referred->hasRole('student')) {
                throw new ReferralException('The referred user is no longer a student — attribution cannot be corrected.');
            }

            if (! $this->isEligibleReferrer($newReferrer, $referred)) {
                throw new ReferralException('The new referrer must be an eligible active student and never the referred student themselves.');
            }

            if ($newReferrer->id === $attribution->referrer_id) {
                return $attribution;
            }

            // The corrected attribution points at the new referrer's own
            // code so code → referrer consistency holds.
            $newCode = $this->codes->getOrCreateForStudent($newReferrer);

            $previousReferrerId = $attribution->referrer_id;
            $previousCodeId = $attribution->referral_code_id;

            $attribution->forceFill([
                'referrer_id' => $newReferrer->id,
                'referral_code_id' => $newCode->id,
            ])->save();

            $this->auditTrail->logOverride(
                $admin,
                'referral_attributions',
                'attribution_corrected',
                sprintf('Attribution #%d referrer corrected from #%d to #%d.', $attribution->id, $previousReferrerId, $newReferrer->id),
                $reason,
                $attribution,
                [
                    'previous_referrer_id' => $previousReferrerId,
                    'new_referrer_id' => $newReferrer->id,
                    'previous_referral_code_id' => $previousCodeId,
                    'new_referral_code_id' => $newCode->id,
                ],
            );

            return $attribution;
        });
    }

    private function isEligibleReferrer(User $referrer, User $referredStudent): bool
    {
        if ($referrer->id === $referredStudent->id) {
            return false;
        }

        // Same-mailbox self-referral through a second account form.
        if (strcasecmp($referrer->email, $referredStudent->email) === 0) {
            return false;
        }

        if (! $referrer->hasRole('student')) {
            return false;
        }

        if ($referrer->status !== User::STATUS_ACTIVE) {
            return false;
        }

        // An "eligible active student" referrer means exactly
        // student_status === Active (Registered/null do not qualify),
        // aligned with the strict lifecycle rule.
        return $referrer->profile?->student_status === StudentStatus::Active;
    }
}
