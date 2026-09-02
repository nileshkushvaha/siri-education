<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Repoints stored Razorpay/Stripe webhook URLs at the real settlement
 * route.
 *
 * Phase 16A.1 §19 fixed the DEFAULT value shown by
 * PaymentSettingsPage, which had pointed at the generic
 * `api/webhooks/payments/...` path — the one that only logs and audits
 * and never settles a booking or credits a wallet. That fix only ever
 * applied to installs with NO stored value: once an operator saves the
 * page, the stored string wins and the corrected default is never
 * consulted again.
 *
 * So any install that configured Razorpay before that fix still holds
 * the wrong URL, and this one was worse than merely wrong. The stored
 * value was:
 *
 *     http://127.0.0.1:8000/api/webhooks/payments/razorpay
 *
 * which matches NEITHER surviving route — not the settlement route,
 * and not the generic one either (that path is
 * `api/webhooks/payments/generic/{gateway}`; this predates the
 * `generic/` segment). An operator who copied it into their Razorpay
 * dashboard would have every delivery answered with a 404: payments
 * succeeding at checkout, Razorpay retrying into nothing, and no
 * booking ever confirmed. Silent, and only visible from the provider's
 * dashboard.
 *
 * Only the PATH is rewritten. Scheme, host and port are preserved
 * exactly, because those are environment-specific and an operator's
 * production hostname must survive this untouched — the bug is which
 * endpoint the path names, never where the server lives.
 *
 * Deliberately limited to razorpay and stripe: they are the only
 * gateways with a registered PaymentProviderInterface adapter, so they
 * are the only ones for which the settlement route resolves at all.
 * paypal/applepay/manual have no adapter and are left pointing where
 * they are — the settlement route would 404 for them, which is exactly
 * the failure this migration exists to remove.
 */
return new class extends SettingsMigration
{
    /** @var list<string> */
    private const GATEWAYS = ['razorpay', 'stripe'];

    public function up(): void
    {
        foreach (self::GATEWAYS as $gateway) {
            $property = "payment_gateways.{$gateway}_webhook_url";

            if (! $this->migrator->exists($property)) {
                continue;
            }

            $this->migrator->update($property, function (?string $current) use ($gateway): ?string {
                return $this->repoint($current, $gateway);
            });
        }
    }

    /**
     * A blank value is left blank on purpose: the settings page renders
     * the (already correct) default for it, so writing one here would
     * only freeze today's hostname into the database for no gain.
     */
    private function repoint(?string $current, string $gateway): ?string
    {
        if (blank($current)) {
            return $current;
        }

        $parts = parse_url((string) $current);

        // An unparseable or host-less value is not something to guess
        // at — leave it for a human rather than inventing a URL.
        if ($parts === false || blank($parts['host'] ?? null)) {
            return $current;
        }

        $correctPath = "/api/webhooks/bookings/payments/{$gateway}";

        if (($parts['path'] ?? '') === $correctPath) {
            return $current;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$parts['host']}{$port}{$correctPath}";
    }

    /**
     * Intentionally irreversible.
     *
     * The previous values named an endpoint that either settles nothing
     * or does not exist. Restoring them would restore a silent
     * money-losing misconfiguration, and there is no version of that
     * worth being able to roll back to.
     */
    public function down(): void {}
};
