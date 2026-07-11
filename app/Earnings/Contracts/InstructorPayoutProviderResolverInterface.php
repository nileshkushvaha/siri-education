<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Exceptions\PayoutProviderException;

/**
 * The single safe seam between "which payout provider is configured"
 * and "may it actually execute a payout right now". Mirrors
 * Booking\Services\PaymentProviderResolver's role for the checkout
 * side, deliberately kept separate (see InstructorPayoutProviderInterface).
 */
interface InstructorPayoutProviderResolverInterface
{
    /** @throws PayoutProviderException when no provider can be used right now */
    public function current(string $currencyCode): InstructorPayoutProviderInterface;

    /** @throws PayoutProviderException when the given provider key cannot be used right now */
    public function resolve(string $key, string $currencyCode): InstructorPayoutProviderInterface;
}
