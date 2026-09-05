<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Models\Country;
use App\Models\User;

/**
 * Hard booking precondition for students: the minimum a student must
 * have told us before they may book anything.
 *
 * Distinct from ProfileCompletionService, which scores optional profile
 * richness as a percentage for the profile page. This one is binary and
 * enforced (EnsureStudentProfileComplete middleware on the booking entry
 * route, WizardBookingService::book()/bookRecurring() server-side).
 *
 * A student who registered through the form already satisfies every rule
 * (the form requires country, phone country, terms). The rules bite for
 * students created from a verified Google identity, who arrive with only
 * a name and an email.
 */
final class StudentProfileCompletenessService
{
    public const string MISSING_NAME = 'name';

    public const string MISSING_COUNTRY = 'country';

    public const string MISSING_PHONE = 'phone';

    public const string MISSING_TERMS = 'terms';

    /** Only students are gated; every other audience is always "complete". */
    public function appliesTo(User $user): bool
    {
        return $user->hasRole('student');
    }

    /** @return list<string> */
    public function missing(User $user): array
    {
        if (! $this->appliesTo($user)) {
            return [];
        }

        $profile = $user->profile;
        $missing = [];

        // Legacy accounts may carry only the combined `name`; either is fine.
        if (trim((string) $user->first_name) === '' && trim((string) $user->name) === '') {
            $missing[] = self::MISSING_NAME;
        }

        if ($profile?->country_id === null || ! $this->isActiveCountry((int) $profile->country_id)) {
            $missing[] = self::MISSING_COUNTRY;
        }

        if (blank($profile?->phone_e164)) {
            $missing[] = self::MISSING_PHONE;
        }

        if ($user->terms_accepted_at === null || $user->privacy_accepted_at === null) {
            $missing[] = self::MISSING_TERMS;
        }

        return $missing;
    }

    public function isComplete(User $user): bool
    {
        return $this->missing($user) === [];
    }

    /**
     * The gate only asks for a real, active country. Whether that country
     * can be BILLED is the form's concern (SupportedRegistrationCountry on
     * CompleteProfileRequest) and the payment step's — a free demo needs
     * none, and a legacy student must not lose booking entirely because
     * their country's currency was later switched off.
     */
    private function isActiveCountry(int $countryId): bool
    {
        return Country::query()->active()->whereKey($countryId)->exists();
    }
}
