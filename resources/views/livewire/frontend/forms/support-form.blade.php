<div>
    <x-ui.card>
        @if($submitted)
            <x-ui.alert type="success">
                Thanks — your support request has been received. We'll get back to you as soon as possible.
            </x-ui.alert>
        @else
            <form wire:submit="submit" class="space-y-4" novalidate>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input wire:model="name" name="name" label="Your name" required autocomplete="name" />
                    <x-ui.input wire:model="email" name="email" type="email" label="Email" required autocomplete="email" />
                </div>

                <x-ui.input wire:model="subject" name="subject" label="Subject" required />

                <x-ui.select
                    wire:model="priority"
                    name="priority"
                    label="Priority"
                    :options="$this->priorityOptions()"
                />

                <x-ui.textarea wire:model="message" name="message" label="How can we help?" required rows="5" />

                <x-ui.honeypot wire:model="website" />

                <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Submit request</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>
