@php
    $gridColumns = [
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-2 lg:grid-cols-3',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
    ][(int) $columns] ?? 'md:grid-cols-2 lg:grid-cols-4';
@endphp

<div>
    @if($teachers->isNotEmpty() || filled($title) || filled($description) || filled($eyebrow))
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

                @if($teachers->isNotEmpty())
                    <div class="mt-10 grid grid-cols-1 gap-5 {{ $gridColumns }}">
                        @foreach($teachers as $teacher)
                            @php
                                $profile = $teacher->profile;
                                $avatarUrl = $profile?->avatarUrl;
                                $summary = $profile?->short_bio ?: $profile?->headline;
                            @endphp

                            <x-ui.card class="h-full">
                                <a href="{{ route('instructors.show', $teacher) }}" class="flex h-full flex-col rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30">
                                    <div class="flex items-center gap-4">
                                        <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-indigo-100 text-lg font-bold text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200">
                                            @if($avatarUrl)
                                                <img src="{{ $avatarUrl }}" alt="{{ $teacher->name }}" class="h-full w-full object-cover">
                                            @else
                                                {{ mb_substr($teacher->name, 0, 1) }}
                                            @endif
                                        </div>

                                        <div class="min-w-0">
                                            <h3 class="truncate text-base font-semibold text-slate-950 dark:text-white">{{ $teacher->name }}</h3>
                                            @if($profile?->headline)
                                                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $profile->headline }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    @if($summary)
                                        <p class="mt-5 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ \Illuminate\Support\Str::limit($summary, 150) }}</p>
                                    @endif
                                </a>
                            </x-ui.card>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
