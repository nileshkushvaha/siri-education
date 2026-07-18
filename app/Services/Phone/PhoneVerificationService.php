<?php

declare(strict_types=1);

namespace App\Services\Phone;

use App\Contracts\PhoneOtpSender;
use App\Contracts\PhoneVerificationServiceInterface;
use App\Models\PhoneVerificationChallenge;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class PhoneVerificationService implements PhoneVerificationServiceInterface
{
    public function __construct(private readonly PhoneOtpSender $sender, private readonly AuditTrailService $audit) {}

    public function send(User $user, string $ip): void
    {
        $profile = $user->profile;
        if (blank($profile?->phone_e164)) {
            throw ValidationException::withMessages(['phone' => 'Add a valid mobile number before requesting a code.']);
        }
        if (! $this->sender->available()) {
            throw ValidationException::withMessages(['phone' => 'Phone verification is temporarily unavailable. Please try again later.']);
        }

        $fingerprint = $this->fingerprint($profile->phone_e164);
        foreach (["phone-otp:user:{$user->id}", "phone-otp:phone:{$fingerprint}", "phone-otp:ip:{$ip}"] as $key) {
            if (RateLimiter::tooManyAttempts($key, 3)) {
                throw ValidationException::withMessages(['phone' => 'Please wait before requesting another verification code.']);
            }
        }

        PhoneVerificationChallenge::query()->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
        $code = (string) random_int(100000, 999999);
        $challenge = PhoneVerificationChallenge::query()->create([
            'user_id' => $user->id, 'phone_fingerprint' => $fingerprint, 'code_hash' => Hash::make($code),
            'attempts_remaining' => 5, 'expires_at' => now()->addMinutes(10),
        ]);

        try {
            $this->sender->send($profile->phone_e164, $code);
        } catch (\Throwable) {
            $challenge->update(['invalidated_at' => now()]);
            throw ValidationException::withMessages(['phone' => 'Phone verification is temporarily unavailable. Please try again later.']);
        }

        foreach (["phone-otp:user:{$user->id}", "phone-otp:phone:{$fingerprint}", "phone-otp:ip:{$ip}"] as $key) {
            RateLimiter::hit($key, 60);
        }
        $this->audit->logUser($user, 'phone_verification', 'otp_sent', 'Phone verification code sent.', $profile, ['masked_phone' => app(PhoneNumberService::class)->masked($profile->phone_e164), 'phone_country_iso2' => $profile->phone_country_iso2]);
    }

    public function verify(User $user, string $code, string $ip): void
    {
        $profile = $user->profile;
        $key = "phone-otp-verify:{$user->id}:{$ip}";
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['otp' => 'Too many attempts. Request a new code later.']);
        }
        RateLimiter::hit($key, 600);

        DB::transaction(function () use ($user, $profile, $code): void {
            $challenge = PhoneVerificationChallenge::query()->where('user_id', $user->id)
                ->where('phone_fingerprint', $this->fingerprint((string) $profile?->phone_e164))
                ->whereNull('consumed_at')->whereNull('invalidated_at')->latest()->lockForUpdate()->first();
            if (! $challenge || $challenge->expires_at->isPast() || $challenge->attempts_remaining < 1 || ! preg_match('/^\d{6}$/', $code) || ! Hash::check($code, $challenge->code_hash)) {
                if ($challenge && $challenge->attempts_remaining > 0) {
                    $challenge->decrement('attempts_remaining');
                }
                throw ValidationException::withMessages(['otp' => 'The verification code is invalid or expired.']);
            }

            $challenge->update(['consumed_at' => now()]);
            $profile->update(['phone_verified_at' => now(), 'phone_verification_status' => 'verified']);
        });
        $this->audit->logUser($user, 'phone_verification', 'phone_verified', 'Mobile number verified.', $profile, ['masked_phone' => app(PhoneNumberService::class)->masked($profile->phone_e164), 'phone_country_iso2' => $profile->phone_country_iso2]);
    }

    public function invalidate(User $user): void
    {
        PhoneVerificationChallenge::query()->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
    }

    private function fingerprint(string $e164): string
    {
        return hash_hmac('sha256', $e164, (string) config('app.key'));
    }
}
