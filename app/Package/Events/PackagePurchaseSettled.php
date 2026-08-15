<?php

declare(strict_types=1);

namespace App\Package\Events;

use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A provider-verified payment settled a package purchase: the Payment
 * is Paid, the purchase is Paid, and the entitlement is Active — all
 * three committed together, which is exactly the invariant
 * PackagePurchaseSettlementService holds.
 *
 * The package counterpart to BookingPaymentSucceeded, and it earns its
 * existence the same way: it is the provider-agnostic seam between
 * "money settled" and "people are told". A Stripe settlement will
 * dispatch this identical event and get identical communications
 * without a single new mail class.
 *
 * ## Dispatched exactly once
 *
 * Only from applySuccess()'s `$result->settled` branch, which is
 * unreachable on a replay: a redelivered webhook finds Payment=Paid
 * AND purchase=Paid and returns `replayed()` instead, which does not
 * dispatch. Both settlement entry points (the verified webhook and the
 * reconciliation sweep) funnel through that one branch, so neither can
 * produce a second copy.
 *
 * ShouldDispatchAfterCommit: a queued listener must never observe —
 * and must certainly never email "payment successful" about — a
 * settlement whose transaction later rolls back.
 */
final class PackagePurchaseSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StudentPackagePurchase $purchase,
        public readonly Payment $payment,
        public readonly StudentPackageEntitlement $entitlement,
    ) {}
}
