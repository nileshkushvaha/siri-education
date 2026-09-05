<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Country;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use Illuminate\Support\Facades\DB;

/**
 * Single-responsibility action: persists the new user. Does NOT dispatch
 * jobs or assign roles — that is the Service's concern.
 *
 * Does NOT create the profile itself — UserObserver::created() already
 * guarantees exactly one UserProfile per user (enforced by a unique
 * index on user_profiles.user_id). Creating a second one here used to
 * silently violate that 1:1 guarantee; this only fills in the one field
 * (phone) the registration form collects that the profile doesn't
 * default.
 */
final class RegisterUserAction
{
    public function __construct(private readonly PhoneNumberService $phones) {}

    public function execute(
        array $data,
        string $status = User::STATUS_PENDING,
        bool $mustChangePassword = false,
    ): User {
        return DB::transaction(function () use ($data, $status, $mustChangePassword): User {
            $fullName = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            $acceptedAt = ($data['terms'] ?? false) ? now() : null;

            $user = User::create([
                'name' => $fullName ?: $data['first_name'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'status' => $status,
                'must_change_password' => $mustChangePassword,
                'terms_accepted_at' => $acceptedAt,
                'privacy_accepted_at' => $acceptedAt,
                'terms_version' => $data['terms_version'] ?? null,
                'privacy_version' => $data['privacy_version'] ?? null,
                'terms_accepted_ip' => $acceptedAt ? ($data['accepted_ip'] ?? null) : null,
                'privacy_accepted_ip' => $acceptedAt ? ($data['accepted_ip'] ?? null) : null,
                'terms_accepted_user_agent' => $acceptedAt ? ($data['accepted_user_agent'] ?? null) : null,
                'privacy_accepted_user_agent' => $acceptedAt ? ($data['accepted_user_agent'] ?? null) : null,
            ]);

            // Country is optional: the register form always sends one, but a
            // student created from a verified Google identity has not chosen
            // one yet and is forced through the complete-profile step before
            // booking (StudentProfileCompletenessService).
            $country = isset($data['country_id']) ? Country::query()->findOrFail($data['country_id']) : null;

            // TZ-1: registration snapshots the country's configured
            // default as the account's INITIAL explicit timezone
            // (model A) — the long-standing behavior, retained
            // deliberately so a brand-new account has a sensible local
            // clock before it ever visits the profile screen.
            //
            // It is only a starting point: the user may change it at
            // any time, and from then on nothing (including a later
            // country change) overwrites their choice — see
            // UpdateProfileAction.
            //
            // The fallback is no longer a hardcoded 'UTC'. A country
            // with no configured default now inherits the platform
            // default, which is the same tier UserTimezoneResolver
            // would have fallen through to anyway — so what is stored
            // at signup and what the resolver would compute agree.
            $profileData = $country === null ? [] : [
                'country_id' => $country->id,
                'timezone' => IanaTimezone::sanitize($country->default_timezone)
                    ?? UserTimezoneResolver::platformDefault(),
            ];

            $phone = $this->phones->normalize($data['phone'] ?? null, $data['phone_country_iso2'] ?? null);
            if ($phone !== null) {
                $profileData += [
                    'phone' => $phone->e164,
                    'phone_country_iso2' => $phone->countryIso2,
                    'phone_dial_code' => $phone->dialCode,
                    'phone_national_number' => $phone->nationalNumber,
                    'phone_e164' => $phone->e164,
                    'phone_verified_at' => null,
                    'phone_verification_status' => 'unverified',
                ];
            }

            if ($profileData !== []) {
                $user->profile()->update($profileData);
            }

            return $user;
        });
    }
}
