<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use App\Settings\AuthenticationSettings;

/**
 * Phase 10.2C-Fix: business-critical account checks belong in the
 * service layer, not only the HTTP middleware stack that already
 * enforces most of this on the dashboard route group
 * (auth/email.verify.if.required/EnsureAccountIsActive) — a booking
 * created through a different entry point (a future API client, an
 * admin acting on a student's behalf, a console command) must not
 * bypass these checks just because it skipped the middleware.
 *
 * Reuses AuthenticationSettings::email_verification_required (the
 * existing toggle EnsureEmailVerifiedIfRequired already respects)
 * rather than hardcoding verification as always-mandatory — this must
 * never disagree with the HTTP-layer check on the same setting.
 */
final class VerifiedActiveStudentRule implements BookingRuleInterface
{
    public function __construct(
        private readonly AuthenticationSettings $authSettings,
        private readonly StudentLifecycleService $studentLifecycle,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        $user = User::find($data->studentId);

        if ($user === null || $user->status !== User::STATUS_ACTIVE) {
            throw new BookingException('Your account is not active. Please contact support.');
        }

        if ($this->authSettings->email_verification_required && $user->email_verified_at === null) {
            throw new BookingException('Please verify your email address before booking a lesson.');
        }

        // Phase 24H.1 — GAP-013 correction: Active is now the
        // authoritative requirement (Registered is rejected too), not
        // merely "not Suspended/Archived". The legacy-backfill concern
        // that originally motivated the weaker check is addressed
        // separately by the students:reconcile-lifecycle-status command
        // — see StudentLifecycleService::isEligibleForStudentActions().
        if (! $this->studentLifecycle->isEligibleForStudentActions($user)) {
            throw new BookingException('Your account is not available for booking. Please contact support.');
        }
    }
}
