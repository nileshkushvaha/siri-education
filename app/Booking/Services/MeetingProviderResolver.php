<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Settings\MeetingSettings;

/**
 * The single safe seam between "which meeting provider is selected" and
 * "may it actually be used right now" — mirrors PaymentProviderResolver.
 *
 * Unlike Phase 11's first draft, `manual` is a real, working provider
 * here (ManualMeetingProvider), not an off switch — the platform-wide
 * off switch is `MeetingSettings::meetings_enabled`.
 */
final class MeetingProviderResolver
{
    public function __construct(
        private readonly MeetingProviderRegistry $registry,
        private readonly MeetingSettings $settings,
    ) {}

    /** @throws BookingException when the configured provider cannot be used right now */
    public function current(): MeetingProviderInterface
    {
        return $this->resolve($this->settings->default_provider);
    }

    /** @throws BookingException when the given provider key cannot be used right now */
    public function resolve(string $key): MeetingProviderInterface
    {
        if (! $this->settings->meetings_enabled) {
            throw new BookingException('Meeting creation is currently disabled platform-wide.');
        }

        $provider = $this->registry->get($key);

        if ($key === ManualMeetingProvider::KEY) {
            if (! $this->settings->manual_provider_enabled) {
                throw new BookingException('The manual meeting provider is disabled.');
            }

            return $provider;
        }

        if (! $provider->isConfigured()) {
            throw new BookingException(sprintf(
                'Meeting provider "%s" is not enabled or its credentials are missing/invalid.',
                $key,
            ));
        }

        return $provider;
    }
}
