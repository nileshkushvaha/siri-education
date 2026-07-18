<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Country;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

final class StudentBillingCountryService
{
    public function assertChangeAllowed(User $user, ?int $countryId): void
    {
        $reason = $this->changeBlockReason($user, $countryId);

        if ($reason !== null) {
            throw ValidationException::withMessages(['country_id' => $reason]);
        }
    }

    public function changeBlockReason(User $user, ?int $countryId): ?string
    {
        $currentCountryId = $user->profile?->country_id;

        if (! $user->hasRole('student')) {
            return null;
        }

        if ($currentCountryId === null && $countryId === null) {
            return null;
        }

        $supported = $countryId !== null && Country::query()
            ->active()
            ->whereKey($countryId)
            ->whereHas('defaultCurrency', fn ($query) => $query->active())
            ->exists();

        if (! $supported) {
            return 'Please select a supported country with an active billing currency.';
        }

        if ($currentCountryId === null || $currentCountryId === $countryId) {
            return null;
        }

        if ($this->isChangeLocked($user)) {
            return 'Your billing country cannot be changed while you have active classes or wallet history. Please contact support.';
        }

        return null;
    }

    public function isChangeLocked(User $user): bool
    {
        if (! $user->hasRole('student') || $user->profile?->country_id === null) {
            return false;
        }

        $hasActiveBooking = Booking::query()->forStudent($user->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->exists();
        $hasWalletHistory = Wallet::query()->forUser($user->id)
            ->where(fn ($query) => $query
                ->where('balance_minor', '!=', 0)
                ->orWhere('available_balance_minor', '!=', 0)
                ->orWhere('held_balance_minor', '!=', 0)
                ->orWhereHas('ledgerEntries'))
            ->exists();

        return $hasActiveBooking || $hasWalletHistory;
    }
}
