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

final class FeedbackForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $name = '';

    public string $email = '';

    public int $rating = 5;

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
        $this->throttleLimiter('forms', ['ip' => request()->ip()], 'message');

        $validated = $this->validate();

        $this->forms->submit(PublicFormType::Feedback, [
            'name' => $validated['name'] ?: 'Anonymous',
            'email' => $validated['email'] ?: null,
            'message' => $validated['message'],
            'meta' => ['rating' => $validated['rating']],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->reset(['name', 'email', 'message', 'website', 'cfTurnstileResponse']);
        $this->rating = 5;
        $this->submitted = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
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
        return view('livewire.frontend.forms.feedback-form');
    }
}
