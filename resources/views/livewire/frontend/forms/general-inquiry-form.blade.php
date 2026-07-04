<div>
    <x-ui.card>
        @if($submitted)
            <x-ui.alert type="success">
                Thanks for reaching out — we've received your message and will respond soon.
            </x-ui.alert>
        @else
            <form wire:submit="submit" class="space-y-4" novalidate>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input wire:model="name" name="name" label="Your name" required autocomplete="name" />
                    <x-ui.input wire:model="email" name="email" type="email" label="Email" required autocomplete="email" />
                </div>

                <x-ui.input wire:model="subject" name="subject" label="Subject" required />

                <x-ui.textarea wire:model="message" name="message" label="Message" required rows="5" />

                <x-ui.honeypot wire:model="website" />

                <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Send message</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>
