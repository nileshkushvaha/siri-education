<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Cms;

use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Rules\TurnstileToken;
use App\Services\NewsletterSubscriptionService;
use App\Settings\BookingSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Newsletter extends Component
{
    use ThrottlesLivewireRequests;

    public string $eyebrow = '';

    public string $title = '';

    public string $description = '';

    public string $nameLabel = '';

    public string $namePlaceholder = '';

    public string $emailLabel = '';

    public string $emailPlaceholder = '';

    public string $buttonText = '';

    public string $consentText = '';

    public string $successMessage = '';

    public string $name = '';

    public string $email = '';

    /** Honeypot — must stay blank. */
    public string $website = '';

    public string $cfTurnstileResponse = '';

    public bool $turnstileEnabled = false;

    public ?string $turnstileSiteKey = null;

    public bool $submitted = false;

    public function mount(
        string $eyebrow = '',
        string $title = '',
        string $description = '',
        string $nameLabel = '',
        string $namePlaceholder = '',
        string $emailLabel = '',
        string $emailPlaceholder = '',
        string $buttonText = '',
        string $consentText = '',
        string $successMessage = '',
    ): void {
        $this->eyebrow = $eyebrow;
        $this->title = $title;
        $this->description = $description;
        $this->nameLabel = $nameLabel;
        $this->namePlaceholder = $namePlaceholder;
        $this->emailLabel = $emailLabel;
        $this->emailPlaceholder = $emailPlaceholder;
        $this->buttonText = $buttonText;
        $this->consentText = $consentText;
        $this->successMessage = $successMessage;

        $settings = app(BookingSettings::class);
        $this->turnstileEnabled = (bool) $settings->captcha_enabled;
        $this->turnstileSiteKey = $settings->turnstile_site_key;
    }

    public function subscribe(NewsletterSubscriptionService $subscriptions): void
    {
        $this->throttleLimiter('forms', ['ip' => request()->ip()], 'email');

        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'website' => ['prohibited'],
            'cfTurnstileResponse' => [new TurnstileToken],
        ], attributes: ['cfTurnstileResponse' => 'security check']);

        $subscriptions->subscribe(
            email: $validated['email'],
            name: $validated['name'] ?: null,
            properties: ['source' => 'cms_homepage'],
        );

        $this->reset('name', 'email', 'website', 'cfTurnstileResponse');
        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.frontend.cms.newsletter');
    }
}
