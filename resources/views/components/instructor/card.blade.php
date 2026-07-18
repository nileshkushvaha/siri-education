@props(['instructor'])

@php
    $subjects = collect($instructor['subjects'] ?? []);
    $languages = collect($instructor['languages'] ?? []);
    $academicLevels = collect($instructor['academic_levels'] ?? []);
    $availability = collect($instructor['availability_preview'] ?? []);
    $ratings = $instructor['ratings'] ?? ['average' => null, 'count' => 0];
    $model = $instructor['model'] ?? null;
    $viewer = auth()->user();
    $isStudentViewer = $viewer?->hasRole('student') ?? false;
    $canFavorite = $isStudentViewer && $model && $viewer->id !== $model->id;
    $isFavorite = $canFavorite ? $viewer->favoriteInstructors()->whereKey($model->id)->exists() : false;
@endphp

<div {{ $attributes->merge(['class' => 'flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-indigo-200 hover:shadow-2xl hover:shadow-indigo-100/70']) }}>
    <div class="group relative flex h-full flex-col p-5 sm:p-6">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-cyan-400 via-indigo-500 to-fuchsia-500" aria-hidden="true"></div>
        <div class="flex items-start gap-4">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-xl font-black text-white shadow-lg shadow-indigo-200">
                @if($instructor['avatar_url'])
                    <img src="{{ $instructor['avatar_url'] }}" alt="{{ $instructor['name'] }}" class="h-full w-full object-cover">
                @else
                    {{ mb_substr($instructor['name'], 0, 1) }}
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <a href="{{ $instructor['url'] }}" class="truncate text-base font-black text-slate-950 transition group-hover:text-indigo-600">
                        {{ $instructor['name'] }}
                    </a>
                    @if($instructor['verified'])
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white shadow-sm shadow-emerald-200">
                            <span class="sr-only">Verified instructor</span>
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.25 7.25a1 1 0 01-1.42 0l-3.25-3.25a1 1 0 111.42-1.42l2.54 2.54 6.54-6.54a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                        </span>
                    @endif
                </div>

                @if($instructor['current_position'] || $instructor['headline'])
                    <p class="mt-1 line-clamp-2 text-sm text-slate-500">
                        {{ $instructor['current_position'] ?: $instructor['headline'] }}
                    </p>
                @endif
            </div>
        </div>

        @if($instructor['summary'])
            <p class="mt-5 line-clamp-3 text-sm leading-6 text-slate-600">{{ $instructor['summary'] }}</p>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            @foreach($subjects as $subject)
                <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-100">
                    {{ $subject['name'] }}
                </span>
            @endforeach
        </div>

        @if($academicLevels->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($academicLevels as $level)
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                        {{ $level['name'] }}
                    </span>
                @endforeach
            </div>
        @endif

        <div class="mt-5 grid gap-3 text-sm text-slate-600">
            @if($languages->isNotEmpty())
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Languages</span>
                    <p class="mt-1">{{ $languages->join(', ') }}</p>
                </div>
            @endif

            @if($instructor['country'] || $instructor['timezone'])
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Location</span>
                    <p class="mt-1">{{ collect([$instructor['country'], $instructor['timezone']])->filter()->join(' · ') }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Rating</span>
                    <p class="mt-1 font-medium text-slate-900">
                        {{ $ratings['average'] !== null ? number_format($ratings['average'], 1) : 'Not rated' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Experience</span>
                    <p class="mt-1 font-medium text-slate-900">
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

        <div class="mt-auto flex items-center gap-3 pt-6">
            <a href="{{ $instructor['url'] }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-2.5 text-sm font-black text-white shadow-md shadow-indigo-100 transition hover:-translate-y-0.5 hover:from-indigo-700 hover:to-violet-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                View profile →
            </a>

            @if($canFavorite)
                <form method="POST" action="{{ $isFavorite ? route('dashboard.favorite-instructors.destroy', $model) : route('dashboard.favorite-instructors.store', $model) }}">
                    @csrf
                    @if($isFavorite)
                        @method('DELETE')
                    @endif
                    <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-600 transition hover:border-indigo-300 hover:bg-indigo-100">
                        <span class="sr-only">{{ $isFavorite ? 'Remove from favorites' : 'Add to favorites' }}</span>
                        <svg class="h-5 w-5" fill="{{ $isFavorite ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/>
                        </svg>
                    </button>
                </form>
            @elseif(! $viewer)
                <form method="POST" action="{{ $model ? route('dashboard.favorite-instructors.store', $model) : '#' }}">
                    @csrf
                    <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-400 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-500">
                        <span class="sr-only">Add to favorites</span>
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/>
                        </svg>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
