<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use App\Models\User;

/**
 * The only boundary that decrypts a payout method's bank details for
 * RazorpayX provisioning purposes. Contact and Fund Account creation
 * are both reuse-before-create and idempotent-safe: calling provision()
 * again on an already-`Ready` link is a no-op, and a timed-out call
 * never spawns a duplicate Contact or Fund Account on retry.
 */
interface RazorpayXDestinationProvisioningServiceInterface
{
    /** Runs the full Contact → Fund Account chain from the link's current state. Creates the link row if none exists yet. */
    public function provision(InstructorPayoutMethod $method, User $actor): InstructorPayoutDestinationProviderLink;

    /** Re-attempts provisioning from whatever step the link is currently stuck at (used to resolve `*_unknown` states or retry after a transient failure). */
    public function refresh(InstructorPayoutDestinationProviderLink $link, User $actor): InstructorPayoutDestinationProviderLink;

    /** Flags a `Ready` link as no longer trustworthy (e.g. reconciliation found a mismatch). Never auto-replaces the Fund Account. */
    public function markStale(InstructorPayoutDestinationProviderLink $link, User $actor, string $reason): InstructorPayoutDestinationProviderLink;

    public function disable(InstructorPayoutDestinationProviderLink $link, User $actor): InstructorPayoutDestinationProviderLink;
}
