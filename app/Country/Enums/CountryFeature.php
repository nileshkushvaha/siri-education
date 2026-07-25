<?php

declare(strict_types=1);

namespace App\Country\Enums;

/**
 * GAP-029 (SRS §20.23/§20.36, §21.25/§21.36) — the only feature keys a
 * country's `feature_flags` override may reference. Each case maps to
 * one real, already-load-bearing global switch and one real
 * enforcement boundary (§21.38 "Country-level feature flag must
 * reference a valid feature" — arbitrary JSON keys are never
 * accepted). A candidate is deliberately absent from this list when no
 * such boundary exists yet — see docs/country-feature-flags.md for the
 * per-case mapping.
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
            default => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
