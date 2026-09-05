<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Profile\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CompleteProfileRequest;
use App\Models\Country;
use App\Services\AuditTrailService;
use App\Services\Auth\GoogleActivationService;
use App\Services\Phone\PhoneNumberService;
use App\Services\PortalResolver;
use App\Services\Student\StudentProfileCompletenessService;
use App\Settings\LocalizationSettings;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Complete your profile" — the booking precondition for students
 * (StudentProfileCompletenessService). Thin: persistence goes through
 * UpdateProfileAction exactly like the full profile page.
 */
class CompleteProfileController extends Controller
{
    public function __construct(
        private readonly StudentProfileCompletenessService $completeness,
        private readonly UpdateProfileAction $updateProfile,
        private readonly PhoneNumberService $phones,
        private readonly LocalizationSettings $localization,
        private readonly PortalResolver $portal,
        private readonly AuditTrailService $audit,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($this->completeness->isComplete($user)) {
            return redirect()->intended($this->portal->loginRedirect($user));
        }

        $countries = Country::active()
            ->whereHas('defaultCurrency', fn ($query) => $query->active())
            ->orderBy('name')
            ->get(['id', 'name', 'iso2', 'phone_code']);

        $phonePlaceholders = $countries->mapWithKeys(fn (Country $country): array => [
            $country->iso2 => $this->phones->exampleNationalNumber($country->iso2),
        ]);

        return view('account.complete-profile', [
            'user' => $user,
            'countries' => $countries,
            'phonePlaceholders' => $phonePlaceholders,
            'missing' => $this->completeness->missing($user),
            'suggestedCountryId' => $this->suggestedCountryId($request, $countries),
        ]);
    }

    public function store(CompleteProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $missingBefore = $this->completeness->missing($user);
        $data = $request->validated();

        $payload = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'country_id' => (int) $data['country_id'],
            'phone' => $data['phone'],
            'phone_country_iso2' => strtoupper($data['phone_country_iso2']),
        ];

        // Same TZ-1 rule as registration: seed the timezone from the chosen
        // country only while the account has none of its own.
        if (blank($user->profile?->timezone)) {
            $country = Country::query()->find($payload['country_id']);
            $payload['timezone'] = IanaTimezone::sanitize($country?->default_timezone)
                ?? UserTimezoneResolver::platformDefault();
        }

        $this->updateProfile->execute($user, $payload);

        if ($user->terms_accepted_at === null || $user->privacy_accepted_at === null) {
            $now = now();
            $user->forceFill([
                'terms_accepted_at' => $user->terms_accepted_at ?? $now,
                'privacy_accepted_at' => $user->privacy_accepted_at ?? $now,
                'terms_accepted_ip' => $user->terms_accepted_ip ?? $request->ip(),
                'privacy_accepted_ip' => $user->privacy_accepted_ip ?? $request->ip(),
                'terms_accepted_user_agent' => $user->terms_accepted_user_agent ?? mb_substr((string) $request->userAgent(), 0, 255),
                'privacy_accepted_user_agent' => $user->privacy_accepted_user_agent ?? mb_substr((string) $request->userAgent(), 0, 255),
            ])->save();
        }

        $request->session()->forget(GoogleActivationService::LOCALE_HINT_SESSION_KEY);

        $this->audit->logUser($user, 'users', 'profile_completed', 'Student completed required profile basics', $user, [
            'source' => 'complete_profile',
            'missing_before' => $missingBefore,
        ]);

        return redirect()->intended(route('booking.create'))
            ->with('success', 'Thanks! Your profile is complete — you can now book lessons.');
    }

    /**
     * Prefill order: what the profile already has → Google locale hint
     * region ("en-IN" → IN) → platform default country. A hint only ever
     * preselects; the student confirms it.
     */
    private function suggestedCountryId(Request $request, $countries): ?int
    {
        $current = $request->user()->profile?->country_id;

        if ($current !== null && $countries->contains('id', $current)) {
            return (int) $current;
        }

        $hint = (string) $request->session()->get(GoogleActivationService::LOCALE_HINT_SESSION_KEY, '');
        $region = strtoupper((string) (preg_match('/[-_]([A-Za-z]{2})$/', $hint, $m) ? $m[1] : ''));

        if ($region !== '' && ($match = $countries->firstWhere('iso2', $region)) !== null) {
            return (int) $match->id;
        }

        $default = $countries->firstWhere('iso2', strtoupper($this->localization->default_country));

        return $default !== null ? (int) $default->id : null;
    }
}
