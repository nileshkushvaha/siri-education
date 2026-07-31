<div>
    @if(filled($title) || filled($description) || filled($eyebrow) || (filled($emailLabel) && filled($buttonText)))
        @if($compact)
            <div class="lg:flex lg:items-end lg:justify-between lg:gap-10">
                <div class="shrink-0">
                    @if(filled($eyebrow))
                        <p class="text-xs font-semibold uppercase tracking-wide text-violet-300">{{ $eyebrow }}</p>
                    @endif

                    @if(filled($title))
                        <h2 class="mt-1.5 text-xl font-black text-white">{{ $title }}</h2>
                    @endif

                    @if(filled($description))
                        <p class="mt-2 max-w-md text-sm leading-6 text-slate-300">{{ $description }}</p>
                    @endif
                </div>

                @if($submitted && filled($successMessage))
                    <div class="mt-4 flex w-full max-w-xl items-center gap-3 rounded-xl border border-emerald-400/20 bg-emerald-400/[0.08] px-4 py-3 text-left shadow-lg shadow-emerald-950/10 backdrop-blur-sm lg:mt-0" role="status" aria-live="polite">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-400/15 text-emerald-300 ring-1 ring-emerald-300/20" aria-hidden="true">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
                        </span>
                        <span class="text-sm font-bold text-emerald-300">{{ $successMessage }}</span>
                    </div>
                @elseif(filled($emailLabel) && filled($buttonText))
                    <form wire:submit="subscribe" class="mt-4 w-full max-w-xl space-y-3 lg:mt-0">
                        @error('website')
                            <p class="text-sm font-medium text-red-400">{{ $message }}</p>
                        @enderror

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <div class="min-w-0 flex-1">
                                <label for="newsletter_email_compact" class="sr-only">{{ $emailLabel }}</label>
                                <input
                                    wire:model="email"
                                    type="email"
                                    id="newsletter_email_compact"
                                    placeholder="{{ $emailPlaceholder }}"
                                    class="block min-h-12 w-full rounded-xl border border-white/15 bg-white/[0.035] px-4 text-base text-white placeholder:text-slate-400 transition focus:border-violet-400/40 focus:outline-none focus:ring-4 focus:ring-violet-400/20"
                                >
                                @error('email')
                                    <p class="mt-1.5 text-xs font-medium text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-r from-indigo-500 to-violet-500 px-6 text-sm font-bold text-white shadow-lg shadow-violet-950/30 transition hover:-translate-y-0.5 hover:shadow-violet-500/20 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25 disabled:opacity-50"
                            >
                                <span wire:loading.remove>{{ $buttonText }}</span>
                                <span wire:loading>…</span>
                            </button>
                        </div>

                        @if(filled($consentText))
                            <p class="text-xs leading-5 text-slate-400">{{ $consentText }}</p>
                        @endif

                        <x-ui.honeypot wire:model="website" />
                        <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" />
                    </form>
                @endif
            </div>
        @else
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
    @endif
</div>
