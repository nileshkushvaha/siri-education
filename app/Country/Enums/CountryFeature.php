<?php

declare(strict_types=1);

namespace App\Country\Enums;

/**
 * SRS §20.23/§20.36, §21.25/§21.36 — the only feature keys a
 * country's `feature_flags` override may reference. Each case maps to
 * one real, already-load-bearing global switch and one real
 * enforcement boundary (§21.38 "Country-level feature flag must
 * reference a valid feature" — arbitrary JSON keys are never
 * accepted). A candidate is deliberately absent from this list when no
 * such boundary exists yet.
 */
enum CountryFeature: string
{
    case PaidBookings = 'paid_bookings';
    case DemoLessons = 'demo_lessons';
    case Wallet = 'wallet';
    case WalletRecharge = 'wallet_recharge';
    case Referrals = 'referrals';
    case PromotionalCredits = 'promotional_credits';
    case Waitlist = 'waitlist';
    case Homework = 'homework';
    case RecordingAvailability = 'recording_availability';

    /**
     * Phase 3 — gates the country-aware Education-System/Curriculum
     * academic selection flow for all direct lesson booking. It is permanent
     * and has no independent global or country switch. Demo availability is
     * still governed separately by DemoLessons; paid booking availability is
     * governed by its payment and pricing rules.
     */
    case CountryAcademicBooking = 'country_academic_booking';

    /**
     * Phase 4D — gates the country-aware academic flow for PERSONALIZED
     * PACKAGES: the instructor proposal's structured academic context
     * (PackageAcademicContext) and the student's package-funded
     * paid_one_to_one booking path.
     *
     * Deliberately a SEPARATE key from CountryAcademicBooking rather
     * than a reuse of it. That flag declares `DemoLessons` as a
     * dependency, so it is switched off platform-wide whenever free
     * demos are switched off — correct for a demo flow, but it would
     * mean an admin disabling free demos silently made every paid
     * package unbookable. Packages are a paid product and must not
     * hang off the demo switch; this case therefore declares no
     * dependency on DemoLessons.
     *
     * Same fail-closed semantics as its demo sibling: off means the
     * structured package flow is simply not offered; on makes the full
     * academic context mandatory for new proposals and for package-
     * funded booking, never a fuzzy fallback (Phase 4D spec §35).
     */
    case CountryAcademicPackages = 'country_academic_packages';

    public function label(): string
    {
        return match ($this) {
            self::PaidBookings => 'Paid Bookings',
            self::DemoLessons => 'Demo Lessons',
            self::Wallet => 'Wallet',
            self::WalletRecharge => 'Wallet Recharge',
            self::Referrals => 'Referrals',
            self::PromotionalCredits => 'Promotional Credits',
            self::Waitlist => 'Waitlist',
            self::Homework => 'Homework',
            self::RecordingAvailability => 'Recording Availability',
            self::CountryAcademicBooking => 'Country-Aware Academic Booking',
            self::CountryAcademicPackages => 'Country-Aware Academic Packages',
        };
    }

    /**
     * SRS §20.32/§21.40 "Country Feature Dependency Missing" — features
     * this one requires to also be effective. Enforced both at runtime
     * (CountryFeatureResolver::isEnabled) and at admin-save time
     * (CountryFeatureDependencyValidator), from this single source so
     * the two can never drift apart.
     *
     * @return list<self>
     */
    public function dependencies(): array
    {
        return match ($this) {
            self::WalletRecharge => [self::Wallet],
            self::PromotionalCredits => [self::Wallet],
            // CountryAcademicPackages deliberately declares NO
            // dependency. PaidBookings would be the tempting one, but
            // it maps to the payment-gateway switch: an already-settled
            // entitlement must remain spendable on a booking even while
            // new collection is paused, and coupling the two would
            // recreate exactly the demo-flag trap this case was split
            // out to avoid. Purchase-time collection is gated by the
            // payment domain's own checks, where it belongs.
            default => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
