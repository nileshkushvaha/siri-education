<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Settings\RazorpayXPayoutSettings;

/**
 * Pure, local, structural validation only — format/presence checks,
 * never a network call. This answers "does the configuration look
 * usable", never "is the RazorpayX account actually active" (that is
 * `RazorpayXInstructorPayoutProvider::healthCheck()`, a genuine network
 * probe, kept deliberately separate per §7 of the phase spec).
 */
final class RazorpayXPayoutConfigurationValidator
{
    private const array ALLOWED_MODES = ['IMPS', 'NEFT', 'RTGS'];

    private const array ALLOWED_PURPOSES = ['payout', 'refund', 'salary', 'utility bill', 'vendor bill'];

    /** @return list<string> empty = structurally valid */
    public function issues(RazorpayXPayoutSettings $settings): array
    {
        $issues = [];

        if (! $this->isValidKeyId($settings->razorpayx_key_id)) {
            $issues[] = 'RazorpayX key_id is missing or does not look like a valid Razorpay key.';
        }

        if (blank($settings->razorpayx_key_secret)) {
            $issues[] = 'RazorpayX key_secret is missing.';
        }

        if (blank($settings->razorpayx_webhook_secret)) {
            $issues[] = 'RazorpayX webhook_secret is missing.';
        }

        if (blank($settings->razorpayx_account_number)) {
            $issues[] = 'RazorpayX source account number is missing.';
        }

        if (! in_array($settings->razorpayx_environment, ['test', 'live'], true)) {
            $issues[] = 'RazorpayX environment must be "test" or "live".';
        }

        if ($settings->razorpayx_expected_outbound_ips === []) {
            $issues[] = 'No expected outbound IP address is configured.';
        }

        if ($settings->razorpayx_ip_allowlisting_confirmed_at === null) {
            $issues[] = 'IP allowlisting has not been explicitly confirmed by an admin.';
        }

        if (! in_array($settings->razorpayx_default_mode, self::ALLOWED_MODES, true)) {
            $issues[] = 'RazorpayX default transfer mode must be one of: '.implode(', ', self::ALLOWED_MODES).'.';
        }

        if (! in_array($settings->razorpayx_default_purpose, self::ALLOWED_PURPOSES, true)) {
            $issues[] = 'RazorpayX default purpose is not one of the allowed values.';
        }

        // Environment/key consistency — a live key with environment=test
        // (or vice versa) is a common, dangerous misconfiguration.
        if ($this->isValidKeyId($settings->razorpayx_key_id)) {
            $keyEnvironment = str_starts_with((string) $settings->razorpayx_key_id, 'rzp_live_') ? 'live' : 'test';

            if ($keyEnvironment !== $settings->razorpayx_environment) {
                $issues[] = sprintf('The configured key_id looks like a %s key but the environment is set to %s.', $keyEnvironment, $settings->razorpayx_environment);
            }
        }

        return $issues;
    }

    public function isStructurallyValid(RazorpayXPayoutSettings $settings): bool
    {
        return $this->issues($settings) === [];
    }

    private function isValidKeyId(?string $keyId): bool
    {
        return $keyId !== null && preg_match('/^rzp_(test|live)_[A-Za-z0-9]+$/', $keyId) === 1;
    }
}
