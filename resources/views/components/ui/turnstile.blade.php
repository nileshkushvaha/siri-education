{{--
    Cloudflare Turnstile widget — a no-op when $enabled is false, so
    forms work unchanged while BookingSettings::captcha_enabled is off.
    Reuses the same site-wide Turnstile credentials as the booking
    wizard (one Cloudflare key pair per domain) via the shared
    App\Rules\TurnstileToken rule on the server side.

    <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" wire-model="cfTurnstileResponse" />
--}}
@props(['enabled' => false, 'siteKey' => null, 'wireModel' => 'cfTurnstileResponse'])

@if($enabled)
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>

    <div
        x-data
        x-init="
            const render = () => {
                if (! window.turnstile || ! $refs.turnstile) {
                    setTimeout(render, 250)
                    return
                }

                window.turnstile.render($refs.turnstile, {
                    sitekey: @js($siteKey),
                    callback: token => $wire.set(@js($wireModel), token),
                    'expired-callback': () => $wire.set(@js($wireModel), ''),
                })
            }
            render()
        "
    >
        <div x-ref="turnstile"></div>
        @error($wireModel) <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p> @enderror
    </div>
@endif
