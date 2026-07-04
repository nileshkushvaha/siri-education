<div>
    @if(filled($title) || filled($description) || filled($eyebrow) || (filled($emailLabel) && filled($buttonText)))
        <section class="py-16 sm:py-20">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <x-ui.card class="text-center">
                    @if(filled($eyebrow))
                        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ $eyebrow }}</p>
                    @endif

                    @if(filled($title))
                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">{{ $title }}</h2>
                    @endif

                    @if(filled($description))
                        <p class="mx-auto mt-4 max-w-2xl text-base leading-7 text-slate-600 dark:text-slate-300">{{ $description }}</p>
                    @endif

                    @if($submitted && filled($successMessage))
                        <x-ui.alert type="success" class="mx-auto mt-8 max-w-xl text-left">
                            {{ $successMessage }}
                        </x-ui.alert>
                    @endif

                    @if(filled($emailLabel) && filled($buttonText))
                        <form wire:submit="subscribe" class="mx-auto mt-8 max-w-2xl space-y-4 text-left">
                            @if(filled($nameLabel))
                                <x-ui.input
                                    wire:model="name"
                                    name="newsletter_name"
                                    :label="$nameLabel"
                                    :placeholder="$namePlaceholder"
                                    :error="$errors->first('name')"
                                />
                            @endif

                            <div class="flex flex-col gap-3 sm:flex-row">
                                <div class="min-w-0 flex-1">
                                    <x-ui.input
                                        wire:model="email"
                                        type="email"
                                        name="newsletter_email"
                                        :label="$emailLabel"
                                        :placeholder="$emailPlaceholder"
                                        :error="$errors->first('email')"
                                    />
                                </div>

                                <div class="sm:self-end">
                                    <x-ui.button type="submit" class="w-full sm:min-h-10" wire:loading.attr="disabled">
                                        {{ $buttonText }}
                                    </x-ui.button>
                                </div>
                            </div>

                            @if(filled($consentText))
                                <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $consentText }}</p>
                            @endif

                            <x-ui.honeypot wire:model="website" />

                            <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />
                        </form>
                    @endif
                </x-ui.card>
            </div>
        </section>
    @endif
</div>
