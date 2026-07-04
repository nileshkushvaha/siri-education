@extends('layouts.frontend')

@section('title', 'Instructor Directory — ' . config('app.name'))

@push('meta')
    <meta name="description" content="Search public instructor profiles by subject, language, availability, and experience.">
    <meta property="og:title" content="Instructor Directory — {{ config('app.name') }}">
    <meta property="og:description" content="Find instructors by subject, language, availability, and experience.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('instructors.index') }}">
@endpush

@section('content')
<div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <section class="border-b border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <x-ui.breadcrumb :items="[
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Instructors'],
            ]" />

            <div class="mt-8 max-w-3xl">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">Instructor Directory</h1>
                <p class="mt-3 text-base leading-7 text-slate-600 dark:text-slate-300">
                    Search public instructor profiles by subject, language, availability, and experience.
                </p>
            </div>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" action="{{ route('instructors.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <div class="grid gap-4 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <x-ui.input
                        name="q"
                        label="Search"
                        value="{{ request('q') }}"
                        placeholder="Name, bio, headline"
                    />
                </div>

                <div class="lg:col-span-3">
                    <x-ui.select name="subject" label="Subject">
                        <option value="">All subjects</option>
                        @foreach($filters['subjects'] as $subject)
                            <option value="{{ $subject['value'] }}" @selected(request('subject') === $subject['value'])>{{ $subject['label'] }}</option>
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
                <x-ui.button type="submit">Search</x-ui.button>

                @if(request()->hasAny(['q', 'subject', 'language', 'sort', 'available']))
                    <x-ui.button href="{{ route('instructors.index') }}" variant="ghost">Clear</x-ui.button>
                @endif

                <p class="ml-auto text-sm text-slate-500 dark:text-slate-400">
                    {{ $instructors->total() }} {{ Str::plural('instructor', $instructors->total()) }}
                </p>
            </div>
        </form>

        @if($instructors->isEmpty())
            <x-ui.empty-state
                title="No instructors found"
                description="Try removing a filter or searching with a different keyword."
                class="mt-8"
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
