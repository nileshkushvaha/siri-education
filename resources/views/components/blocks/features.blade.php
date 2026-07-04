{{-- Features Block --}}
@php
    $features = collect($features ?? [])->filter(fn ($feature) => filled($feature['title'] ?? null));

    $gridColumns = [
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-2 lg:grid-cols-3',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
    ][(int) ($columns ?? 3)] ?? 'md:grid-cols-2 lg:grid-cols-3';
@endphp

@if($features->isNotEmpty() || filled($title ?? null) || filled($description ?? null) || filled($eyebrow ?? null))
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

            @if($features->isNotEmpty())
                <div class="mt-10 grid grid-cols-1 gap-5 {{ $gridColumns }}">
                    @foreach($features as $feature)
                        <x-ui.card class="h-full">
                            <div class="flex h-full flex-col">
                                @if(filled($feature['icon'] ?? null))
                                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-sm font-bold text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-300">
                                        {{ mb_substr($feature['icon'], 0, 2) }}
                                    </div>
                                @endif

                                <h3 class="text-base font-semibold text-slate-950 dark:text-white">{{ $feature['title'] }}</h3>

                                @if(filled($feature['description'] ?? null))
                                    <p class="mt-3 flex-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $feature['description'] }}</p>
                                @endif

                                @if(filled($feature['link'] ?? null) && filled($feature['link_label'] ?? null))
                                    <a href="{{ $feature['link'] }}" class="mt-5 inline-flex items-center text-sm font-semibold text-indigo-600 transition hover:text-indigo-500 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:text-indigo-400 dark:hover:text-indigo-300 dark:focus-visible:ring-indigo-400/30">
                                        {{ $feature['link_label'] }}
                                    </a>
                                @endif
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
