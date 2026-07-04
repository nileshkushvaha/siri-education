@php
    $items = collect($courses)->filter(fn ($course) => filled($course['title'] ?? null));
    $gridColumns = [
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-2 lg:grid-cols-3',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
    ][(int) $columns] ?? 'md:grid-cols-2 lg:grid-cols-3';
@endphp

<div>
    @if($items->isNotEmpty() || filled($title) || filled($description) || filled($eyebrow))
        <section class="py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                @if(filled($eyebrow) || filled($title) || filled($description) || (filled($linkLabel) && filled($linkUrl)))
                    <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                        <div class="max-w-3xl">
                            @if(filled($eyebrow))
                                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ $eyebrow }}</p>
                            @endif

                            @if(filled($title))
                                <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">{{ $title }}</h2>
                            @endif

                            @if(filled($description))
                                <p class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-300">{{ $description }}</p>
                            @endif
                        </div>

                        @if(filled($linkLabel) && filled($linkUrl))
                            <x-ui.button href="{{ $linkUrl }}" variant="secondary">{{ $linkLabel }}</x-ui.button>
                        @endif
                    </div>
                @endif

                @if($items->isNotEmpty())
                    <div class="mt-10 grid grid-cols-1 gap-5 {{ $gridColumns }}">
                        @foreach($items as $course)
                            @php
                                $chips = collect([
                                    $course['category'] ?? null,
                                    $course['level'] ?? null,
                                    $course['duration'] ?? null,
                                ])->filter();
                            @endphp

                            <x-ui.card :padded="false" class="h-full overflow-hidden">
                                @if(filled($course['url'] ?? null))
                                    <a href="{{ $course['url'] }}" class="block h-full rounded-2xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30">
                                @else
                                    <div class="h-full">
                                @endif
                                    @if(filled($course['image'] ?? null))
                                        <img src="{{ $course['image'] }}" alt="{{ $course['title'] }}" class="h-44 w-full object-cover">
                                    @endif

                                    <div class="flex h-full flex-col p-5 sm:p-6">
                                        @if(filled($course['badge'] ?? null))
                                            <x-ui.badge color="indigo" class="mb-4 w-fit">{{ $course['badge'] }}</x-ui.badge>
                                        @endif

                                        <h3 class="text-base font-semibold text-slate-950 dark:text-white">{{ $course['title'] }}</h3>

                                        @if(filled($course['description'] ?? null))
                                            <p class="mt-3 flex-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $course['description'] }}</p>
                                        @endif

                                        @if(filled($course['instructor'] ?? null))
                                            <p class="mt-4 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $course['instructor'] }}</p>
                                        @endif

                                        @if($chips->isNotEmpty() || filled($course['price'] ?? null))
                                            <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
                                                @foreach($chips as $chip)
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $chip }}</span>
                                                @endforeach

                                                @if(filled($course['price'] ?? null))
                                                    <span class="ml-auto text-sm font-semibold text-slate-950 dark:text-white">{{ $course['price'] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @if(filled($course['url'] ?? null))
                                    </a>
                                @else
                                    </div>
                                @endif
                            </x-ui.card>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
