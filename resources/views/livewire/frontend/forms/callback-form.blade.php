<div>
    <x-ui.card>
        @if($submitted)
            <x-ui.alert type="success">
                Thanks — we've received your callback request and will reach out shortly.
            </x-ui.alert>
        @else
            <form wire:submit="submit" class="space-y-4" novalidate>
                <x-ui.input wire:model="name" name="name" label="Your name" required autocomplete="name" />

                <x-ui.input wire:model="phone" name="phone" type="tel" label="Phone number" required autocomplete="tel" />

                <x-ui.input
                    wire:model="preferredTime"
                    name="preferredTime"
                    label="Preferred time to call"
                    hint="Optional — e.g. weekday mornings, after 5pm"
                />

                <x-ui.textarea
                    wire:model="message"
                    name="message"
                    label="What would you like to discuss?"
                    hint="Optional"
                    rows="4"
                />

                <x-ui.honeypot wire:model="website" />

                <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Request a callback</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>
