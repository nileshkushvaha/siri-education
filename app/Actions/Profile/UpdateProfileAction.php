<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Contracts\PhoneVerificationServiceInterface;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use App\Services\Student\StudentBillingCountryService;
use Illuminate\Support\Facades\DB;

final class UpdateProfileAction
{
    public function __construct(
        private readonly StudentBillingCountryService $billingCountries,
        private readonly PhoneNumberService $phones,
        private readonly PhoneVerificationServiceInterface $phoneVerification,
    ) {}

    public function execute(User $user, array $data): User
    {
        if (array_key_exists('country_id', $data)) {
            $this->billingCountries->assertChangeAllowed($user, $data['country_id']);
        }

        if (array_key_exists('phone', $data)) {
            $phone = $this->phones->normalize($data['phone'], $data['phone_country_iso2'] ?? null);
            $existing = $user->profile?->phone_e164;
            $changed = $phone?->e164 !== $existing;
            $data['phone'] = $phone?->e164;
            $data['phone_country_iso2'] = $phone?->countryIso2;
            $data['phone_dial_code'] = $phone?->dialCode;
            $data['phone_national_number'] = $phone?->nationalNumber;
            $data['phone_e164'] = $phone?->e164;
            if ($changed) {
                $data['phone_verified_at'] = null;
                $data['phone_verification_status'] = $phone ? 'unverified' : null;
                $this->phoneVerification->invalidate($user);
            }
        }

        return DB::transaction(function () use ($user, $data): User {

            // ── Update core user fields ───────────────────────────────
            // Email is frozen — intentionally never written here, even if a
            // caller passes one in $data.
            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            ]);

            // Every user is guaranteed a profile row (UserObserver on create,
            // backfilled for pre-existing users) — no nullable-check needed.
            $profile = $user->profile;
            $existingNotifPrefs = $profile->notification_preferences ?? [];

            // ── Merge: only overwrite a field when the request actually sent it.
            //    Each tab only posts its own fields — other fields keep their
            //    existing DB value instead of being blanked to null.
            $profile->update([
                // General tab fields
                'headline' => array_key_exists('headline', $data) ? ($data['headline'] ?? null) : $profile->headline,
                'short_bio' => array_key_exists('short_bio', $data) ? ($data['short_bio'] ?? null) : $profile->short_bio,
                'bio' => array_key_exists('bio', $data) ? ($data['bio'] ?? null) : $profile->bio,
                'phone' => array_key_exists('phone', $data) ? ($data['phone'] ?? null) : $profile->phone,
                'phone_country_iso2' => array_key_exists('phone_country_iso2', $data) ? $data['phone_country_iso2'] : $profile->phone_country_iso2,
                'phone_dial_code' => array_key_exists('phone_dial_code', $data) ? $data['phone_dial_code'] : $profile->phone_dial_code,
                'phone_national_number' => array_key_exists('phone_national_number', $data) ? $data['phone_national_number'] : $profile->phone_national_number,
                'phone_e164' => array_key_exists('phone_e164', $data) ? $data['phone_e164'] : $profile->phone_e164,
                'phone_verified_at' => array_key_exists('phone_verified_at', $data) ? $data['phone_verified_at'] : $profile->phone_verified_at,
                'phone_verification_status' => array_key_exists('phone_verification_status', $data) ? $data['phone_verification_status'] : $profile->phone_verification_status,
                'gender' => array_key_exists('gender', $data) ? ($data['gender'] ?? null) : $profile->gender,
                'date_of_birth' => array_key_exists('date_of_birth', $data) ? ($data['date_of_birth'] ?? null) : $profile->date_of_birth,
                'address' => array_key_exists('address', $data) ? ($data['address'] ?? null) : $profile->address,
                'city' => array_key_exists('city', $data) ? ($data['city'] ?? null) : $profile->city,
                'country_id' => array_key_exists('country_id', $data) ? ($data['country_id'] ?? null) : $profile->country_id,
                'state_id' => array_key_exists('state_id', $data) ? ($data['state_id'] ?? null) : $profile->state_id,
                'postal_code' => array_key_exists('postal_code', $data) ? ($data['postal_code'] ?? null) : $profile->postal_code,

                // Social links
                'website' => array_key_exists('website', $data) ? ($data['website'] ?? null) : $profile->website,
                'facebook' => array_key_exists('facebook', $data) ? ($data['facebook'] ?? null) : $profile->facebook,
                'twitter' => array_key_exists('twitter', $data) ? ($data['twitter'] ?? null) : $profile->twitter,
                'linkedin' => array_key_exists('linkedin', $data) ? ($data['linkedin'] ?? null) : $profile->linkedin,
                'github' => array_key_exists('github', $data) ? ($data['github'] ?? null) : $profile->github,
                'instagram' => array_key_exists('instagram', $data) ? ($data['instagram'] ?? null) : $profile->instagram,
                'youtube' => array_key_exists('youtube', $data) ? ($data['youtube'] ?? null) : $profile->youtube,

                // Localisation — fall back to saved value to prevent null constraint errors
                'timezone' => $data['timezone'] ?? $profile->timezone ?? 'Asia/Kolkata',
                'language' => $data['language'] ?? $profile->language ?? 'en',

                // Notifications tab — merge individual keys so toggling one
                // field doesn't reset the others to their defaults
                'notification_preferences' => [
                    'email_notifications' => array_key_exists('email_notifications', $data)
                        ? (bool) $data['email_notifications']
                        : (bool) ($existingNotifPrefs['email_notifications'] ?? true),
                    'system_notifications' => array_key_exists('system_notifications', $data)
                        ? (bool) $data['system_notifications']
                        : (bool) ($existingNotifPrefs['system_notifications'] ?? true),
                    'marketing_emails' => array_key_exists('marketing_emails', $data)
                        ? (bool) $data['marketing_emails']
                        : (bool) ($existingNotifPrefs['marketing_emails'] ?? false),
                ],
            ]);

            return $user->fresh(['profile']);
        });
    }
}
