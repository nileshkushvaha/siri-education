{{-- Pricing Block --}}
@php
    $plans = collect($plans ?? [])->filter(fn ($plan) => filled($plan['name'] ?? null));

    $gridColumns = [
        2 => 'md:grid-cols-2',
        3 => 'lg:grid-cols-3',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
    ][(int) ($columns ?? 3)] ?? 'lg:grid-cols-3';
@endphp

@if($plans->isNotEmpty() || filled($title ?? null) || filled($description ?? null) || filled($eyebrow ?? null))
    <section class="py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @if(filled($eyebrow ?? null) || filled($title ?? null) || filled($description ?? null))
                <div class="mx-auto max-w-3xl text-center">
                    @if(filled($eyebrow ?? null))
                        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ $eyebrow }}</p>
                    @endif

                    @if(filled($title ?? null))
                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">{{ $title }}</h2>
                    @endif

                    @if(filled($description ?? null))
                        <p class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-300">{{ $description }}</p>
                    @endif
                </div>
            @endif

            @if($plans->isNotEmpty())
                <div class="mt-10 grid grid-cols-1 gap-6 {{ $gridColumns }}">
                    @foreach($plans as $plan)
                        @php
                            $includedFeatures = is_array($plan['features'] ?? null)
                                ? collect($plan['features'])->filter()
                                : collect(preg_split('/\r\n|\r|\n/', (string) ($plan['features'] ?? '')) ?: [])->filter();

                            $isHighlighted = (bool) ($plan['highlighted'] ?? false);
                        @endphp

                        <x-ui.card class="relative flex h-full flex-col {{ $isHighlighted ? 'border-indigo-500 ring-2 ring-indigo-500/20 dark:border-indigo-400' : '' }}">
                            @if(filled($plan['badge'] ?? null))
                                <x-ui.badge color="{{ $isHighlighted ? 'indigo' : 'slate' }}" class="mb-5 w-fit">
                                    {{ $plan['badge'] }}
                                </x-ui.badge>
                            @endif

                            <div>
                                <h3 class="text-lg font-semibold text-slate-950 dark:text-white">{{ $plan['name'] }}</h3>

                                @if(filled($plan['description'] ?? null))
                                    <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $plan['description'] }}</p>
                                @endif
                            </div>

                            @if(filled($plan['price'] ?? null) || filled($plan['period'] ?? null))
                                <div class="mt-6 flex items-baseline gap-2">
                                    @if(filled($plan['price'] ?? null))
                                        <span class="text-4xl font-bold tracking-tight text-slate-950 dark:text-white">{{ $plan['price'] }}</span>
                                    @endif

                                    @if(filled($plan['period'] ?? null))
                                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ $plan['period'] }}</span>
                                    @endif
                                </div>
                            @endif

                            @if($includedFeatures->isNotEmpty())
                                <ul class="mt-6 space-y-3 text-sm text-slate-700 dark:text-slate-300">
                                    @foreach($includedFeatures as $feature)
                                        <li class="flex gap-3">
                                            <svg class="mt-0.5 h-5 w-5 flex-none text-emerald-600 dark:text-emerald-400" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                            <span>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if(filled($plan['button_text'] ?? null) && filled($plan['button_link'] ?? null))
                                <x-ui.button
                                    href="{{ $plan['button_link'] }}"
                                    variant="{{ $isHighlighted ? 'primary' : 'secondary' }}"
                                    class="mt-8 w-full"
                                >
                                    {{ $plan['button_text'] }}
                                </x-ui.button>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
