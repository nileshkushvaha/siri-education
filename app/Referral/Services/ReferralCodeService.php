<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralCodeStatus;
use App\Referral\Exceptions\ReferralException;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;

/**
 * The single authority for referral-code ownership. Codes are generated
 * lazily (first visit to the Refer a Friend page, or explicitly in
 * tests) — never backfilled in a migration. The unique indexes on
 * user_id and code are the final guards; this service only bounds the
 * retries around them.
 */
final class ReferralCodeService implements ReferralCodeServiceInterface
{
    /**
     * Unambiguous uppercase alphabet — I, L, O, 0 and 1 excluded so a
     * shared code survives handwriting and voice. 31^8 ≈ 8.5e11
     * combinations keeps blind guessing impractical at this length.
     */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const int CODE_LENGTH = 8;

    private const int MAX_GENERATION_ATTEMPTS = 5;

    /** Normalized shape a submitted code must have to be worth a lookup. */
    private const string CODE_PATTERN = '/^[A-Z0-9]{4,20}$/';

    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly StudentLifecycleService $lifecycle,
    ) {}

    public function getOrCreateForStudent(User $user): ReferralCode
    {
        if (! $user->hasRole('student')) {
            throw new ReferralException('Referral codes are issued to students only.');
        }

        // Initiating referral participation (including this lazy
        // create-on-first-visit) is an interactive student action
        // requiring an Active student. Already-earned/held rewards
        // remain governed separately (ReferralRewardService).
        $this->lifecycle->assertEligibleForStudentAction($user);

        $existing = ReferralCode::query()->where('user_id', $user->id)->first();

        // Idempotent — including a Disabled row: a disabled code is a
        // deliberate administrative state, never silently regenerated.
        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 1; $attempt <= self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            try {
                $code = ReferralCode::query()->create([
                    'user_id' => $user->id,
                    'code' => $this->randomCode(),
                    'status' => ReferralCodeStatus::Active,
                ]);

                $this->auditTrail->logUser(
                    $user,
                    'referral_codes',
                    'created',
                    sprintf('Referral code generated for user #%d.', $user->id),
                    $code,
                    ['user_id' => $user->id],
                );

                return $code;
            } catch (QueryException $e) {
                // Lost a race on the user_id unique index — another
                // request just created this student's code; return it.
                $winner = ReferralCode::query()->where('user_id', $user->id)->first();

                if ($winner !== null) {
                    return $winner;
                }

                // Otherwise a code collision — retry with a fresh code.
                if ($attempt === self::MAX_GENERATION_ATTEMPTS) {
                    throw new ReferralException(
                        sprintf('Could not generate a unique referral code after %d attempts.', $attempt),
                        previous: $e,
                    );
                }
            }
        }

        throw new ReferralException('Could not generate a unique referral code.');
    }

    public function findActiveByCode(?string $rawCode): ?ReferralCode
    {
        $code = self::normalize($rawCode);

        if ($code === null || preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }

        return ReferralCode::query()
            ->where('code', $code)
            ->where('status', ReferralCodeStatus::Active)
            ->first();
    }

    public function disable(ReferralCode $code, User $actor, string $reason): ReferralCode
    {
        if (! $actor->can('DisableReferralCodes')) {
            throw new AuthorizationException;
        }

        if (trim($reason) === '') {
            throw new ReferralException('Disabling a referral code requires a reason.');
        }

        if ($code->status === ReferralCodeStatus::Disabled) {
            return $code;
        }

        $code->forceFill([
            'status' => ReferralCodeStatus::Disabled,
            'disabled_at' => now(),
            'disabled_by' => $actor->id,
            'disable_reason' => $reason,
        ])->save();

        $this->auditTrail->logOverride(
            $actor,
            'referral_codes',
            'disabled',
            sprintf('Referral code #%d disabled.', $code->id),
            $reason,
            $code,
            ['user_id' => $code->user_id],
        );

        return $code->refresh();
    }

    /** Uppercase-trimmed canonical form shared by generation and lookup. */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtoupper(trim($raw));

        return $normalized === '' ? null : $normalized;
    }

    private function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
