<div>
    <x-ui.card>
        @if($submitted)
            <x-ui.alert type="success">
                Thank you for your feedback — it helps us improve.
            </x-ui.alert>
        @else
            <form wire:submit="submit" class="space-y-4" novalidate>
                <fieldset>
                    <legend class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">How would you rate your experience?</legend>
                    <div class="flex items-center gap-2" role="radiogroup" aria-label="Rating, 1 to 5">
                        @for($i = 1; $i <= 5; $i++)
                            <button
                                type="button"
                                wire:click="$set('rating', {{ $i }})"
                                aria-pressed="{{ $rating === $i ? 'true' : 'false' }}"
                                aria-label="{{ $i }} out of 5"
                                class="flex h-10 w-10 items-center justify-center rounded-xl border text-sm font-semibold transition
                                    {{ $rating >= $i
                                        ? 'border-indigo-500 bg-indigo-600 text-white'
                                        : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:bg-white/10' }}"
                            >
                                {{ $i }}
                            </button>
                        @endfor
                    </div>
                    @error('rating') <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p> @enderror
                </fieldset>

                <x-ui.input wire:model="name" name="name" label="Your name" hint="Optional" autocomplete="name" />

                <x-ui.input wire:model="email" name="email" type="email" label="Email" hint="Optional — only if you'd like a reply" autocomplete="email" />

                <x-ui.textarea wire:model="message" name="message" label="Your feedback" required rows="5" />

                <x-ui.honeypot wire:model="website" />

                <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Send feedback</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>
