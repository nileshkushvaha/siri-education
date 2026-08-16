<?php

use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Payments\RazorpayPaymentProvider;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Multi-country collection: Razorpay for every launch market.
 *
 * Three changes:
 *
 * 1. `razorpay_international_currencies` — the currencies THIS merchant
 *    account is confirmed to collect. Razorpay's supported set is
 *    per-account (their docs require a support request for anything
 *    outside the standard list), so a static list in code cannot be
 *    authoritative. Seeded with the six confirmed by Razorpay's public
 *    documentation; NZD and SAR are deliberately excluded until an
 *    operator verifies them on the Dashboard.
 *
 * 2. The rollout scope collapses to `active_country_routing`. The old
 *    `india_razorpay_only` encoded a single market in an enum, so every
 *    new market meant a code change and the enum could disagree with the
 *    country data. Markets are now opened purely through
 *    `Country.status` + `Country.payment_routing`.
 *
 * `default_provider` is deliberately left alone. Every launch market
 * carries an explicit `payment_routing` entry, so the platform default
 * is only a fallback for countries that are not launch markets — and
 * those are inactive and refused before routing is consulted. Pinning it
 * would also override the documented resolution precedence for any
 * non-launch context that legitimately selects another provider.
 *
 * Nothing here activates international collection:
 * `razorpay_international_enabled` is untouched and remains false until
 * an operator attests the Razorpay account.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Guarded so a partially applied run can be retried. `add()` throws
        // SettingAlreadyExists and `update()` throws SettingDoesNotExist — if the first
        // statement succeeded and a later one failed, the migration is never recorded, so
        // the retry would hit the already-created property and fail forever.
        if (! $this->migrator->exists('payment_gateways.razorpay_international_currencies')) {
            $this->migrator->add(
                'payment_gateways.razorpay_international_currencies',
                RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES,
            );
        }

        if ($this->migrator->exists('payment_gateways.payment_collection_rollout_scope')) {
            $this->migrator->update(
                'payment_gateways.payment_collection_rollout_scope',
                fn (): string => PaymentCollectionRolloutScope::ActiveCountryRouting->value,
            );

            return;
        }

        $this->migrator->add(
            'payment_gateways.payment_collection_rollout_scope',
            PaymentCollectionRolloutScope::ActiveCountryRouting->value,
        );
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('payment_gateways.razorpay_international_currencies');
    }
};
