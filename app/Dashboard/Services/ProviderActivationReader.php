<?php

declare(strict_types=1);

namespace App\Dashboard\Services;

use App\Dashboard\DTOs\ProviderActivationState;
use App\Filament\Pages\Settings\PaymentGatewayPage;
use App\Filament\Pages\Settings\RazorpayXPayoutSettingsPage;
use App\Models\User;
use App\Settings\PaymentGatewaySettings;
use App\Settings\RazorpayXPayoutSettings;

/**
 * Reads real-money provider activation state so the dashboard can tell
 * "no financial activity" apart from "this provider was never turned
 * on". Rendering an empty collections figure as evidence of zero
 * business would be a factual misstatement while activation is still
 * pending — see `docs/financial-provider-activation-handoff.md`.
 *
 * Two independent facts are combined, exactly as the settings classes
 * define them:
 *
 *  - `*_enabled` — the administrator's on/off switch.
 *  - `*_config_status` — written only by the domain's own configuration
 *    service (`PaymentGatewayConfigurationService`) and set to `ready`
 *    only when credentials actually pass validation.
 *
 * Either alone is insufficient, so {@see ProviderActivationState::isActivated()}
 * requires both.
 *
 * No credential, key fragment, account number or webhook secret is ever
 * read or exposed here — only the boolean switch and the status word.
 */
final readonly class ProviderActivationReader
{
    private const string STATUS_READY = 'ready';

    public function __construct(
        private PaymentGatewaySettings $gateways,
        private RazorpayXPayoutSettings $payouts,
        private DashboardPermissions $permissions,
    ) {}

    /**
     * Collection (student-facing) providers.
     *
     * @return list<ProviderActivationState>
     */
    public function collectionProviders(User $user): array
    {
        // The settings page owns its own access rule
        // (`HasSettingsAccess`: super admin, or any `settings.*`
        // permission). It is consulted rather than reimplemented, so a
        // link is offered only where the page would actually open.
        $canOpenSettings = $this->canOpenSettings($user, PaymentGatewayPage::canAccess(...));

        return [
            $this->state(
                key: 'razorpay',
                label: 'Razorpay (collection)',
                enabled: $this->gateways->razorpay_enabled,
                status: $this->gateways->razorpay_config_status,
                sandbox: $this->gateways->razorpay_sandbox_mode,
                settingsUrl: $canOpenSettings ? PaymentGatewayPage::getUrl() : null,
            ),
            $this->state(
                key: 'stripe',
                label: 'Stripe (collection)',
                enabled: $this->gateways->stripe_enabled,
                status: $this->gateways->stripe_config_status,
                sandbox: $this->gateways->stripe_sandbox_mode,
                settingsUrl: $canOpenSettings ? PaymentGatewayPage::getUrl() : null,
            ),
        ];
    }

    /**
     * Payout (instructor-facing) providers.
     *
     * @return list<ProviderActivationState>
     */
    public function payoutProviders(User $user): array
    {
        $canOpenSettings = $this->canOpenSettings($user, RazorpayXPayoutSettingsPage::canAccess(...));

        return [
            $this->state(
                key: 'razorpayx',
                label: 'RazorpayX (instructor payout)',
                enabled: $this->payouts->razorpayx_enabled,
                status: $this->payouts->razorpayx_config_status,
                sandbox: $this->payouts->razorpayx_environment !== 'live',
                settingsUrl: $canOpenSettings ? RazorpayXPayoutSettingsPage::getUrl() : null,
            ),
        ];
    }

    /** @return list<ProviderActivationState> */
    public function all(User $user): array
    {
        return [...$this->collectionProviders($user), ...$this->payoutProviders($user)];
    }

    /**
     * A short, honest note for the money summary, or null when at least
     * one collection provider is genuinely live. This is what stops an
     * empty collections figure reading as "no sales".
     */
    public function collectionNotice(User $user): ?string
    {
        $providers = $this->collectionProviders($user);

        foreach ($providers as $provider) {
            if ($provider->isActivated()) {
                return null;
            }
        }

        return 'No collection provider is activated yet, so external payment figures reflect configuration state — not an absence of demand.';
    }

    /**
     * Filament page gates read `auth()->user()` by design. The
     * dashboard always composes for the authenticated user, so the
     * page's own rule is authoritative — but the identity is asserted
     * here rather than assumed, so a composition built for some other
     * user can never inherit the current session's settings access.
     *
     * @param  callable(): bool  $gate
     */
    private function canOpenSettings(User $user, callable $gate): bool
    {
        $authenticated = auth()->user();

        if ($authenticated === null || ! $authenticated->is($user)) {
            return false;
        }

        return $gate();
    }

    private function state(
        string $key,
        string $label,
        bool $enabled,
        string $status,
        bool $sandbox,
        ?string $settingsUrl,
    ): ProviderActivationState {
        $credentialsReady = $status === self::STATUS_READY;

        return new ProviderActivationState(
            key: $key,
            label: $label,
            enabled: $enabled,
            credentialsReady: $credentialsReady,
            statusLabel: match (true) {
                $enabled && $credentialsReady && ! $sandbox => 'Live',
                $enabled && $credentialsReady => 'Test mode',
                $enabled => 'Enabled, credentials not verified',
                default => 'Not activated',
            },
            detail: match (true) {
                ! $enabled => 'Switched off in payment settings.',
                ! $credentialsReady => sprintf('Credential check reports "%s".', $status),
                $sandbox => 'Running against the provider\'s test environment.',
                default => 'Credentials verified and live.',
            },
            settingsUrl: $settingsUrl,
        );
    }
}
