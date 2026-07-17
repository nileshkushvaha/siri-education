<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Enums\StudentStatus;
use App\Models\ReferralAttribution;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralAttributionSource;
use App\Referral\Events\ReferralAttributed;
use App\Services\AuditTrailService;
use App\Settings\FeatureSettings;
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
        private readonly FeatureSettings $features,
        private readonly AuditTrailService $auditTrail,
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
        if (! $this->features->referral_enabled) {
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
            return DB::transaction(function () use ($referredStudent, $code, $referrer, $attributionSource): ReferralAttribution {
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

        $studentStatus = $referrer->profile?->student_status;

        return ! in_array($studentStatus, [StudentStatus::Suspended, StudentStatus::Archived], true);
    }
}
