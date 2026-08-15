<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BookingPayment;
use App\Models\StudentPackagePurchase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 4B.1 — the generic payment domain (App\Payments\*), used by NEW
 * payment consumers only. The legacy Booking and Wallet payment flows
 * keep their own wiring in BookingServiceProvider and are untouched;
 * see docs/generic-payable-payment-foundation.md for why that split is
 * deliberate and transitional.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerPayableMorphMap();
    }

    /**
     * Stable aliases for `payments.payable_type`. A FQCN must never be
     * persisted: renaming or relocating a payable class would otherwise
     * orphan every historical payment row pointing at it.
     *
     * Relation::morphMap() MERGES by default, so this coexists with the
     * CMS aliases registered in CmsServiceProvider rather than
     * replacing them — do not consolidate the two.
     *
     * `Booking` and `WalletRecharge` are deliberately absent, and for
     * different reasons. Booking is not itself a payable — PAY-4 makes
     * BOOKING_PAYMENT the payable, because the obligation is what gets
     * paid, not the lesson. WalletRecharge keeps its own legacy path.
     *
     * Adding an alias is append-only: no existing value may ever change,
     * or historical rows stop hydrating.
     *
     * @var array<string, class-string>
     */
    private const PAYABLE_MORPH_MAP = [
        StudentPackagePurchase::PAYABLE_TYPE => StudentPackagePurchase::class,
        BookingPayment::PAYABLE_TYPE => BookingPayment::class,
    ];

    private function registerPayableMorphMap(): void
    {
        Relation::morphMap(self::PAYABLE_MORPH_MAP);
    }
}
