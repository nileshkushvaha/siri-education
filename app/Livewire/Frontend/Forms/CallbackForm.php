<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Forms;

use App\Forms\Contracts\PublicFormServiceInterface;
use App\Forms\Enums\PublicFormType;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Rules\TurnstileToken;
use App\Settings\BookingSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CallbackForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $name = '';

    public string $phone = '';

    public string $preferredTime = '';

    public string $message = '';

    /** Honeypot — must stay blank. */
    public string $website = '';

    public string $cfTurnstileResponse = '';

    public bool $turnstileEnabled = false;

    public ?string $turnstileSiteKey = null;

    public bool $submitted = false;

    private PublicFormServiceInterface $forms;

    public function boot(PublicFormServiceInterface $forms): void
    {
        $this->forms = $forms;
    }

    public function mount(): void
    {
        $settings = app(BookingSettings::class);
        $this->turnstileEnabled = (bool) $settings->captcha_enabled;
        $this->turnstileSiteKey = $settings->turnstile_site_key;
    }

    public function submit(): void
    {
        $this->throttleLimiter('forms', ['ip' => request()->ip()], 'name');

        $validated = $this->validate();

        $this->forms->submit(PublicFormType::Callback, [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'message' => $validated['message'] ?: null,
            'meta' => array_filter(['preferred_time' => $validated['preferredTime'] ?: null]),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->reset(['name', 'phone', 'preferredTime', 'message', 'website', 'cfTurnstileResponse']);
        $this->submitted = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'preferredTime' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:4000'],
            'website' => ['prohibited'],
            'cfTurnstileResponse' => [new TurnstileToken],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'preferredTime' => 'preferred time',
            'cfTurnstileResponse' => 'security check',
        ];
    }

    public function render(): View
    {
        return view('livewire.frontend.forms.callback-form');
    }
}
