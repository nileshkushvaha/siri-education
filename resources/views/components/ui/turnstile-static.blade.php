{{--
    Cloudflare Turnstile widget for classic (non-Livewire) POST forms —
    e.g. the CMS Contact Form block. Uses Cloudflare's implicit
    rendering: the .cf-turnstile div auto-creates and submits a hidden
    "cf-turnstile-response" field with the form, no JS glue required.
    Server-side, validate it with App\Rules\TurnstileToken.

    For Livewire forms, use <x-ui.turnstile> instead (wire:model-based).

    <x-ui.turnstile-static />
--}}
@php
    $settings = app(\App\Settings\BookingSettings::class);
@endphp

@if($settings->captcha_enabled)
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <div class="cf-turnstile" data-sitekey="{{ $settings->turnstile_site_key }}"></div>
    @error('cf-turnstile-response')
        <p class="mt-1.5 text-xs font-medium text-red-500">{{ $message }}</p>
    @enderror
@endif
