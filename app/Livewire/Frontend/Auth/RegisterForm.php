<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Exceptions\Auth\RegistrationException;
use App\Http\Requests\Auth\RegisterRequest;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Models\Country;
use App\Services\Auth\RegistrationCaptchaService;
use App\Services\Auth\RegistrationService;
use App\Services\PortalResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Thin Livewire replacement for the classic POST /register form.
 * Mirrors RegisterController::store() exactly, delegating all
 * registration logic (approval workflow, auto-verification, password
 * history, activity log) to RegistrationService — the existing
 * controller/route stay in place, untouched, as the non-JS fallback.
 */
final class RegisterForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $first_name = '';

    public ?string $last_name = '';

    public string $email = '';

    public ?string $phone = '';

    public string $phone_country_iso2 = 'US';

    public bool $phone_country_was_manually_changed = false;

    public ?int $country_id = null;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $terms = false;

    public string $captcha_answer = '';

    public string $captchaQuestion = '';

    /** @var array<int, array{id: int, iso2: string, label: string, currency: string, dial_code: string}> */
    public array $countries = [];

    public ?string $referral_code = '';

    /**
     * The normalized code the ?ref= query parameter prefilled, if any —
     * lets register() record whether the submitted code still came from
     * the shareable link or was hand-edited (informational only; both
     * run the identical backend validation).
     */
    public ?string $prefilledReferralCode = null;

    public ?string $banner = null;

    public function mount(RegistrationCaptchaService $captcha): void
    {
        $this->countries = Country::query()->active()
            ->whereHas('defaultCurrency', fn ($query) => $query->active())
            ->with('defaultCurrency:id,code')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'iso2', 'name', 'phone_code', 'default_currency_id'])
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'iso2' => $country->iso2,
                'label' => $country->name,
                'currency' => $country->defaultCurrency->code,
                'dial_code' => $country->phone_code,
            ])->all();
        $us = collect($this->countries)->firstWhere('iso2', 'US');
        $this->country_id = isset($us['id']) ? (int) $us['id'] : null;
        $this->captchaQuestion = $captcha->issue();

        $ref = request()->query('ref');

        if (is_string($ref) && trim($ref) !== '') {
            $this->referral_code = mb_substr(trim($ref), 0, 32);
            $this->prefilledReferralCode = strtoupper($this->referral_code);
        }
    }

    public function updatedCountryId(): void
    {
        if (! $this->phone_country_was_manually_changed) {
            $country = collect($this->countries)->firstWhere('id', $this->country_id);
            $this->phone_country_iso2 = $country['iso2'] ?? 'US';
        }
    }

    public function updatedPhoneCountryIso2(): void
    {
        $this->phone_country_was_manually_changed = true;
    }

    public function useResidenceCountry(): void
    {
        $country = collect($this->countries)->firstWhere('id', $this->country_id);
        $this->phone_country_iso2 = $country['iso2'] ?? 'US';
        $this->phone_country_was_manually_changed = false;
    }

    public function refreshCaptcha(RegistrationCaptchaService $captcha): void
    {
        $this->captcha_answer = '';
        $this->resetValidation('captcha_answer');
        $this->captchaQuestion = $captcha->issue();
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return (new RegisterRequest)->rules();
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return (new RegisterRequest)->messages();
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return (new RegisterRequest)->attributes();
    }

    public function register(RegistrationService $registrationService, PortalResolver $portal): void
    {
        $this->banner = null;

        // A classic HTTP request runs through Laravel's global
        // ConvertEmptyStringsToNull middleware before validation, which
        // is why RegisterRequest's 'nullable' phone rule tolerates an
        // empty field. Livewire validates component properties directly
        // (no such middleware), so an untouched '' would otherwise hit
        // the regex and fail — normalize the same way here instead of
        // relaxing the shared rule itself.
        $this->last_name = blank($this->last_name) ? null : $this->last_name;
        $this->phone = blank($this->phone) ? null : $this->phone;
        $this->referral_code = blank($this->referral_code) ? null : trim($this->referral_code);

        $this->validate();
        $this->throttleLimiter('login', ['email' => $this->email], 'email');

        try {
            $result = $registrationService->register(
                data: [
                    'first_name' => $this->first_name,
                    'last_name' => $this->last_name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'phone_country_iso2' => $this->phone_country_iso2,
                    'country_id' => $this->country_id,
                    'password' => $this->password,
                    'terms' => $this->terms,
                    'captcha_answer' => $this->captcha_answer,
                    'referral_code' => $this->referral_code,
                    'referral_code_source' => $this->referral_code !== null
                        && $this->prefilledReferralCode === strtoupper($this->referral_code)
                        ? 'link'
                        : 'manual',
                ],
                ipAddress: request()->ip() ?? '',
                userAgent: request()->userAgent() ?? '',
            );
        } catch (RegistrationException $e) {
            $this->banner = $e->getMessage();

            return;
        }

        if ($result->requiresApproval) {
            session()->flash('success', 'Your account has been created and is awaiting administrator approval. You will be notified by email.');
            $this->redirect(route('auth.login'), navigate: false);

            return;
        }

        if ($result->autoVerified) {
            Auth::login($result->user);
            session()->regenerate();

            session()->flash('success', 'Welcome to '.config('app.name').'! Your account is ready.');
            $this->redirect($portal->loginRedirect($result->user), navigate: false);

            return;
        }

        // Normal flow: log in temporarily so the signed verification URL works.
        Auth::login($result->user);
        session()->regenerate();

        session()->flash('success', 'Account created! Please check your email to verify your address before signing in.');
        $this->redirect(route('auth.verification.notice'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.register-form');
    }
}
