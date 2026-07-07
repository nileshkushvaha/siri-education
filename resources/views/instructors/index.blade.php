@extends('layouts.frontend')

@section('title', 'Instructor Directory — ' . config('app.name'))

@push('meta')
    <meta name="description" content="Find verified STEM instructors by subject, academic level, teaching language, location, and experience.">
    <meta property="og:title" content="Instructor Directory — {{ config('app.name') }}">
    <meta property="og:description" content="Find verified STEM instructors by subject, academic level, teaching language, location, and experience.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('instructors.index') }}">
@endpush

@section('page-flash', true)

@section('content')
<div class="min-h-screen bg-slate-950 text-slate-100">
    <section class="relative overflow-hidden border-b border-white/10 bg-slate-950">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-400 via-emerald-300 to-fuchsia-400" aria-hidden="true"></div>
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
            <x-ui.breadcrumb :items="[
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Instructors'],
            ]" />

            @if(session()->has('success') || session()->has('error') || session()->has('warning') || session()->has('info'))
                <div class="mt-8 space-y-3">
                    @if(session('success'))
                        <div class="flex items-start gap-3 rounded-2xl border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100 shadow-lg shadow-emerald-950/20" role="alert">
                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-400 text-slate-950">
                                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.25 7.25a1 1 0 01-1.42 0l-3.25-3.25a1 1 0 111.42-1.42l2.54 2.54 6.54-6.54a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold">Favorites updated</p>
                                <p class="mt-0.5 text-emerald-100/80">{{ session('success') }}</p>
                            </div>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="rounded-2xl border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-100" role="alert">{{ session('error') }}</div>
                    @endif
                    @if(session('warning'))
                        <div class="rounded-2xl border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-sm text-amber-100" role="alert">{{ session('warning') }}</div>
                    @endif
                    @if(session('info'))
                        <div class="rounded-2xl border border-sky-400/25 bg-sky-400/10 px-4 py-3 text-sm text-sky-100" role="alert">{{ session('info') }}</div>
                    @endif
                </div>
            @endif

            <div class="mt-10 grid gap-8 lg:grid-cols-3 lg:items-end">
                <div class="max-w-4xl lg:col-span-2">
                    <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                        Instructor Directory
                    </div>
                    <h1 class="max-w-4xl text-4xl font-black tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Find the right STEM instructor for your next learning goal.
                    </h1>
                    <p class="mt-5 max-w-3xl text-base leading-8 text-slate-300 sm:text-lg">
                        Compare public instructor profiles by subject expertise, academic level, teaching language, location, and experience.
                    </p>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5 shadow-2xl shadow-black/20">
                    <p class="text-sm font-semibold text-white">Discovery snapshot</p>
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-900/80 p-4">
                            <p class="text-2xl font-black text-white">{{ $instructors->total() }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ Str::plural('public instructor', $instructors->total()) }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-900/80 p-4">
                            <p class="text-2xl font-black text-white">{{ $filters['subjects']->count() }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ Str::plural('subject', $filters['subjects']->count()) }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-slate-400">Only active, public, bookable instructor profiles appear here.</p>
                </div>
            </div>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" action="{{ route('instructors.index') }}" class="-mt-14 rounded-3xl border border-white/10 bg-slate-900/95 p-4 shadow-2xl shadow-black/30 backdrop-blur sm:p-5 lg:p-6">
            <div class="mb-5 flex flex-col gap-3 border-b border-white/10 pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-white">Refine your search</h2>
                    <p class="mt-1 text-sm text-slate-400">Start broad, then narrow by subject, level, language, and location.</p>
                </div>
                <p class="text-sm font-semibold text-slate-300">
                    {{ $instructors->total() }} {{ Str::plural('match', $instructors->total()) }}
                </p>
            </div>

            <div class="grid gap-4 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <x-ui.input
                        name="q"
                        label="Search instructors"
                        value="{{ request('q') }}"
                        placeholder="Try Algebra, robotics, exam prep"
                    />
                </div>

                <div class="lg:col-span-4">
                    <x-ui.select name="subject" label="Subject">
                        <option value="">All subjects</option>
                        @foreach($filters['subjects'] as $subject)
                            <option value="{{ $subject['value'] }}" @selected(request('subject') === $subject['value'])>{{ $subject['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="lg:col-span-4">
                    <x-ui.select name="academic_level" label="Academic level">
                        <option value="">All levels</option>
                        @foreach($filters['academic_levels'] as $level)
                            <option value="{{ $level['value'] }}" @selected(request('academic_level') === $level['value'])>{{ $level['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="lg:col-span-2">
                    <x-ui.select name="language" label="Language">
                        <option value="">All languages</option>
                        @foreach($filters['languages'] as $language)
                            <option value="{{ $language['value'] }}" @selected(request('language') === $language['value'])>{{ $language['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="lg:col-span-2">
                    <x-ui.select name="country" label="Country">
                        <option value="">All countries</option>
                        @foreach($filters['countries'] as $country)
                            <option value="{{ $country['value'] }}" @selected((string) request('country') === (string) $country['value'])>{{ $country['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="lg:col-span-2">
                    <x-ui.select name="timezone" label="Timezone">
                        <option value="">All timezones</option>
                        @foreach($filters['timezones'] as $timezone)
                            <option value="{{ $timezone['value'] }}" @selected(request('timezone') === $timezone['value'])>{{ $timezone['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="lg:col-span-2">
                    <x-ui.select name="sort" label="Sort">
                        <option value="featured" @selected(request('sort', 'featured') === 'featured')>Featured</option>
                        <option value="name" @selected(request('sort') === 'name')>Name A-Z</option>
                        <option value="newest" @selected(request('sort') === 'newest')>Newest</option>
                    </x-ui.select>
                </div>

                <div class="flex items-end lg:col-span-1">
                    <label class="flex min-h-10 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                        <input type="checkbox" name="available" value="1" @checked(request()->boolean('available')) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="sr-only">Has availability</span>
                        <span aria-hidden="true">Open</span>
                    </label>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-ui.button type="submit">Apply filters</x-ui.button>

                @if(request()->hasAny(['q', 'subject', 'academic_level', 'language', 'country', 'timezone', 'sort', 'available']))
                    <x-ui.button href="{{ route('instructors.index') }}" variant="ghost">Clear</x-ui.button>
                @endif
            </div>
        </form>

        @if($instructors->isEmpty())
            <x-ui.empty-state
                title="No instructors found"
                description="Try clearing one or two filters, or search by a broader subject area."
                class="mt-8 border border-white/10 bg-slate-900/80 text-slate-100"
            />
        @else
            <div class="mt-8 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach($instructors as $instructor)
                    <x-instructor.card :instructor="$instructor" />
                @endforeach
            </div>

            @if($instructors->hasPages())
                <div class="mt-8">
                    <x-ui.pagination :paginator="$instructors" />
                </div>
            @endif
        @endif
    </main>
</div>
@endsection
