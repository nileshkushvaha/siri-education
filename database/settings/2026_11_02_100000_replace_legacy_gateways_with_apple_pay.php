<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Retires the three gateways that were never implemented beyond their
 * settings rows (Cashfree, PayU, PhonePe) and introduces Apple Pay in
 * their place.
 *
 * None of the three had a provider adapter, a webhook parser entry, or
 * a routing entry in PaymentProviderResolver::SUPPORTED_CURRENCIES —
 * they were admin forms collecting credentials that nothing could ever
 * use. Keeping them on screen implied a capability the platform does
 * not have, which is worse than absent for anything money-related.
 *
 * `down()` restores them with their original defaults so the migration
 * is genuinely reversible; it deliberately does NOT restore any
 * credentials that were stored against them, because those are
 * encrypted values this migration never had.
 */
return new class extends SettingsMigration
{
    /** @var list<string> */
    private array $retired = ['cashfree', 'payu', 'phonepe'];

    public function up(): void
    {
        foreach ($this->retired as $gateway) {
            foreach ($this->fieldsFor($gateway) as $field) {
                $this->migrator->deleteIfExists("payment_gateways.{$gateway}_{$field}");
            }
        }

        $this->migrator->add('payment_gateways.applepay_enabled', false);
        $this->migrator->add('payment_gateways.applepay_sandbox_mode', true);
        $this->migrator->add('payment_gateways.applepay_merchant_id', null);
        $this->migrator->add('payment_gateways.applepay_merchant_domain', null);
        $this->migrator->add('payment_gateways.applepay_merchant_certificate', null);
        $this->migrator->add('payment_gateways.applepay_merchant_key', null);
        $this->migrator->add('payment_gateways.applepay_webhook_secret', null);
        $this->migrator->add('payment_gateways.applepay_success_url', null);
        $this->migrator->add('payment_gateways.applepay_failure_url', null);
        $this->migrator->add('payment_gateways.applepay_webhook_url', null);
    }

    public function down(): void
    {
        foreach (['applepay_enabled', 'applepay_sandbox_mode', 'applepay_merchant_id', 'applepay_merchant_domain', 'applepay_merchant_certificate', 'applepay_merchant_key', 'applepay_webhook_secret', 'applepay_success_url', 'applepay_failure_url', 'applepay_webhook_url'] as $field) {
            $this->migrator->deleteIfExists("payment_gateways.{$field}");
        }

        $this->migrator->add('payment_gateways.cashfree_enabled', false);
        $this->migrator->add('payment_gateways.cashfree_environment', 'sandbox');
        $this->migrator->add('payment_gateways.cashfree_app_id', null);
        $this->migrator->add('payment_gateways.cashfree_secret_key', null);
        $this->migrator->add('payment_gateways.cashfree_webhook_secret', null);
        $this->migrator->add('payment_gateways.cashfree_success_url', null);
        $this->migrator->add('payment_gateways.cashfree_failure_url', null);
        $this->migrator->add('payment_gateways.cashfree_webhook_url', null);

        foreach (['payu', 'phonepe'] as $gateway) {
            $this->migrator->add("payment_gateways.{$gateway}_enabled", false);
            $this->migrator->add("payment_gateways.{$gateway}_sandbox_mode", true);
            $this->migrator->add("payment_gateways.{$gateway}_merchant_id", null);
            $this->migrator->add("payment_gateways.{$gateway}_webhook_secret", null);
            $this->migrator->add("payment_gateways.{$gateway}_success_url", null);
            $this->migrator->add("payment_gateways.{$gateway}_failure_url", null);
            $this->migrator->add("payment_gateways.{$gateway}_webhook_url", null);
        }

        $this->migrator->add('payment_gateways.payu_public_key', null);
        $this->migrator->add('payment_gateways.payu_private_key', null);
        $this->migrator->add('payment_gateways.phonepe_salt_key', null);
        $this->migrator->add('payment_gateways.phonepe_salt_index', null);
    }

    /** @return list<string> */
    private function fieldsFor(string $gateway): array
    {
        $shared = ['enabled', 'merchant_id', 'webhook_secret', 'success_url', 'failure_url', 'webhook_url', 'sandbox_mode'];

        return match ($gateway) {
            'cashfree' => ['enabled', 'environment', 'app_id', 'secret_key', 'webhook_secret', 'success_url', 'failure_url', 'webhook_url'],
            'payu' => [...$shared, 'public_key', 'private_key'],
            'phonepe' => [...$shared, 'salt_key', 'salt_index'],
            default => $shared,
        };
    }
};
