@props(['instructor'])

@php
    $subjects = collect($instructor['subjects'] ?? []);
    $languages = collect($instructor['languages'] ?? []);
    $availability = collect($instructor['availability_preview'] ?? []);
    $ratings = $instructor['ratings'] ?? ['average' => null, 'count' => 0];
@endphp

<x-ui.card class="flex h-full flex-col" {{ $attributes }}>
    <a href="{{ $instructor['url'] }}" class="group flex h-full flex-col rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30">
        <div class="flex items-start gap-4">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-indigo-100 text-xl font-bold text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200">
                @if($instructor['avatar_url'])
                    <img src="{{ $instructor['avatar_url'] }}" alt="{{ $instructor['name'] }}" class="h-full w-full object-cover">
                @else
                    {{ mb_substr($instructor['name'], 0, 1) }}
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <h2 class="truncate text-base font-semibold text-slate-950 group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-300">
                        {{ $instructor['name'] }}
                    </h2>
                    @if($instructor['verified'])
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300">
                            <span class="sr-only">Verified instructor</span>
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.25 7.25a1 1 0 01-1.42 0l-3.25-3.25a1 1 0 111.42-1.42l2.54 2.54 6.54-6.54a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                        </span>
                    @endif
                </div>

                @if($instructor['current_position'] || $instructor['headline'])
                    <p class="mt-1 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">
                        {{ $instructor['current_position'] ?: $instructor['headline'] }}
                    </p>
                @endif
            </div>
        </div>

        @if($instructor['summary'])
            <p class="mt-5 line-clamp-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $instructor['summary'] }}</p>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @foreach($subjects as $subject)
                <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-200">
                    {{ $subject['name'] }}
                </span>
            @endforeach
        </div>

        <div class="mt-5 grid gap-3 text-sm text-slate-600 dark:text-slate-300">
            @if($languages->isNotEmpty())
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Languages</span>
                    <p class="mt-1">{{ $languages->join(', ') }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Rating</span>
                    <p class="mt-1 font-medium text-slate-900 dark:text-white">
                        {{ $ratings['average'] !== null ? number_format($ratings['average'], 1) : 'Not rated' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Experience</span>
                    <p class="mt-1 font-medium text-slate-900 dark:text-white">
                        {{ $instructor['years_experience'] > 0 ? $instructor['years_experience'].' years' : 'New' }}
                    </p>
                </div>
            </div>

            @if($availability->isNotEmpty())
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Availability Preview</span>
                    <div class="mt-2 space-y-1">
                        @foreach($availability as $slot)
                            <p>{{ $slot['day'] }} · {{ $slot['time'] }}</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </a>
</x-ui.card>
