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
                        <p class="text-sm font-black uppercase tracking-[0.15em] text-indigo-600">{{ $eyebrow }}</p>
                    @endif

                    @if(filled($title ?? null))
                        <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ $title }}</h2>
                    @endif

                    @if(filled($description ?? null))
                        <p class="mt-4 text-base leading-7 text-slate-600">{{ $description }}</p>
                    @endif
                </div>
            @endif

            @if($features->isNotEmpty())
                <div class="mt-10 grid grid-cols-1 gap-5 {{ $gridColumns }}">
                    @foreach($features as $feature)
                        <article class="h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-100/60">
                            <div class="flex h-full flex-col">
                                @if(filled($feature['icon'] ?? null))
                                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-100 to-violet-100 text-sm font-black text-indigo-700 ring-1 ring-indigo-100">
                                        {{ mb_substr($feature['icon'], 0, 2) }}
                                    </div>
                                @endif

                                <h3 class="text-base font-black text-slate-950">{{ $feature['title'] }}</h3>

                                @if(filled($feature['description'] ?? null))
                                    <p class="mt-3 flex-1 text-sm leading-6 text-slate-600">{{ $feature['description'] }}</p>
                                @endif

                                @if(filled($feature['link'] ?? null) && filled($feature['link_label'] ?? null))
                                    <a href="{{ $feature['link'] }}" class="mt-5 inline-flex items-center text-sm font-black text-indigo-600 transition hover:text-indigo-500 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">
                                        {{ $feature['link_label'] }}
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
