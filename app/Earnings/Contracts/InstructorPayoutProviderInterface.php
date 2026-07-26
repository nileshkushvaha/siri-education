<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\DTOs\PayoutInitiationResult;
use App\Earnings\DTOs\PayoutProviderCapabilities;
use App\Earnings\DTOs\PayoutProviderHealth;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Exceptions\PayoutProviderException;
use Illuminate\Http\Request;

/**
 * The provider-neutral payout boundary. Implemented per provider
 * (fake and RazorpayX today), registered in EarningServiceProvider,
 * selected via InstructorEarningSettings::payout_provider.
 * InstructorPayoutExecutionService never knows which provider is
 * active. Deliberately distinct from
 * Booking\Contracts\PaymentProviderInterface: payout semantics
 * (initiate/status/reversal/reconciliation) are not checkout
 * semantics, and conflating the two boundaries would let a change on
 * one side silently break the other.
 */
interface InstructorPayoutProviderInterface
{
    /** Stable identifier used in settings and provider-event rows (snake_case). */
    public function providerName(): string;

    public function supportsCurrency(string $currencyCode): bool;

    /**
     * Structural validation of a destination snapshot — format/presence
     * checks only, never a network call. Returns a UI-safe reason, or
     * null when the destination is acceptable.
     *
     * @param  array<string, mixed>  $destinationSnapshot
     */
    public function validateDestination(array $destinationSnapshot): ?string;

    /**
     * @throws PayoutProviderException when the request cannot even be attempted
     */
    public function initiate(PayoutInitiationRequest $request): PayoutInitiationResult;

    /** @throws PayoutProviderException */
    public function fetchStatus(string $providerPayoutId): PayoutStatusResult;

    /** True if the attempt was cancelled at the provider; false if the provider does not support it. */
    public function cancelWhenSupported(string $providerPayoutId): bool;

    /** @throws PayoutProviderException when the event cannot be trusted */
    public function normalizeEvent(Request $request): NormalizedPayoutEvent;

    /** Never a network call for the fake provider; a real adapter may probe a lightweight status endpoint. */
    public function healthCheck(): PayoutProviderHealth;

    /**
     * The provider's static, declared shape. The generic payout domain
     * reads this instead of ever branching on a provider name — see
     * `InstructorPayoutEligibilityService`, which is the only caller
     * that should need it.
     */
    public function capabilities(): PayoutProviderCapabilities;
}
