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

final class SupportForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public string $priority = 'normal';

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
        $this->throttleLimiter('forms', ['ip' => request()->ip()], 'email');

        $validated = $this->validate();

        $this->forms->submit(PublicFormType::Support, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'meta' => ['priority' => $validated['priority']],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->reset(['name', 'email', 'subject', 'message', 'website', 'cfTurnstileResponse']);
        $this->priority = 'normal';
        $this->submitted = true;
    }

    /** @return array<string, string> */
    public function priorityOptions(): array
    {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'priority' => ['required', 'in:low,normal,high'],
            'message' => ['required', 'string', 'max:4000'],
            'website' => ['prohibited'],
            'cfTurnstileResponse' => [new TurnstileToken],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'cfTurnstileResponse' => 'security check',
        ];
    }

    public function render(): View
    {
        return view('livewire.frontend.forms.support-form');
    }
}
