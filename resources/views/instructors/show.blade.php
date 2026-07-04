@extends('layouts.frontend')

@section('title', $instructor->name . ' — Instructor — ' . config('app.name'))

@push('meta')
    <meta name="description" content="{{ $profile->short_bio ?: Str::limit($profile->bio ?? $instructor->name . ' is an instructor on ' . config('app.name'), 160) }}">
    <meta property="og:title" content="{{ $instructor->name }} — Instructor">
    <meta property="og:description" content="{{ Str::limit($profile->bio ?? $instructor->name . ' is an instructor on ' . config('app.name'), 200) }}">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="{{ route('instructors.show', $instructor) }}">
    @if($profile->avatarUrl)
        <meta property="og:image" content="{{ $profile->avatarUrl }}">
    @endif
    <link rel="canonical" href="{{ route('instructors.show', $instructor) }}">
@endpush

@push('structured_data')
@php
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $instructor->name,
        'url' => route('instructors.show', $instructor),
    ];
    if ($profile->bio) {
        $jsonLd['description'] = Str::limit(strip_tags($profile->bio), 500);
    }
    if ($profile->avatarUrl) {
        $jsonLd['image'] = $profile->avatarUrl;
    }
    if ($currentPosition) {
        $jsonLd['jobTitle'] = $currentPosition->designation;
    }
@endphp
<script type="application/ld+json">{{ json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</script>
@endpush

@section('content')
<div class="min-h-screen bg-slate-50 dark:bg-slate-950">
    <section class="border-b border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <x-ui.breadcrumb :items="[
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Instructors', 'url' => route('instructors.index')],
                ['label' => $instructor->name],
            ]" />

            <div class="mt-8 flex flex-col gap-6 md:flex-row md:items-end">
                <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-3xl bg-indigo-100 text-3xl font-bold text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200">
                    @if($profile->avatarUrl)
                        <img src="{{ $profile->avatarUrl }}" alt="{{ $instructor->name }}" class="h-full w-full object-cover">
                    @else
                        {{ mb_substr($instructor->name, 0, 1) }}
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">{{ $instructor->name }}</h1>
                        @if($profile->is_instructor_verified)
                            <x-ui.badge color="success">Verified</x-ui.badge>
                        @endif
                    </div>

                    @if($currentPosition)
                        <p class="mt-2 text-base text-slate-600 dark:text-slate-300">
                            {{ $currentPosition->designation }}@if($currentPosition->organization_name) · {{ $currentPosition->organization_name }}@endif
                        </p>
                    @elseif($profile->headline)
                        <p class="mt-2 text-base text-slate-600 dark:text-slate-300">{{ $profile->headline }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @if($profile->bio)
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">About</h2>
                        <p class="mt-4 whitespace-pre-line text-sm leading-7 text-slate-600 dark:text-slate-300">{{ $profile->bio }}</p>
                    </x-ui.card>
                @endif

                @if($subjects->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Subjects</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($subjects as $subject)
                                <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $subject['name'] }}</p>
                                    @if($subject['grade_range'])
                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subject['grade_range'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($experiences->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Experience</h2>
                        <div class="mt-5 space-y-5">
                            @foreach($experiences as $experience)
                                <div class="border-l-2 border-indigo-200 pl-4 dark:border-indigo-400/30">
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $experience->designation }}</p>
                                    @if($experience->organization_name)
                                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $experience->organization_name }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ optional($experience->start_date)->format('M Y') }}
                                        @if($experience->end_date)
                                            - {{ $experience->end_date->format('M Y') }}
                                        @elseif($experience->is_current)
                                            - Present
                                        @endif
                                    </p>
                                    @if($experience->description)
                                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $experience->description }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($skills->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Skills</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($skills as $skill)
                                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-200">{{ $skill }}</span>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($certificates->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Certificates</h2>
                        <div class="mt-4 space-y-3">
                            @foreach($certificates as $certificate)
                                <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                    <p class="font-medium text-slate-950 dark:text-white">{{ $certificate->degree ?? $certificate->education_level?->label() }}</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $certificate->institution_name }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $certificate->certificate_number }}</p>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </div>

            <aside class="space-y-6">
                <x-ui.card>
                    <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Instructor Snapshot</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Rating</dt>
                            <dd class="font-medium text-slate-950 dark:text-white">{{ $ratings['average'] !== null ? number_format($ratings['average'], 1) : 'Not rated' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Experience</dt>
                            <dd class="font-medium text-slate-950 dark:text-white">{{ $stats['years_experience'] > 0 ? $stats['years_experience'].' years' : 'New' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Subjects</dt>
                            <dd class="font-medium text-slate-950 dark:text-white">{{ $subjects->count() }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                @if($languages->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Languages</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($languages as $language)
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ $language }}</span>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card>
                    <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Availability Preview</h2>
                    @if($availabilityPreview->isNotEmpty())
                        <div class="mt-4 space-y-3 text-sm">
                            @foreach($availabilityPreview as $slot)
                                <div class="flex items-center justify-between gap-4 rounded-xl bg-slate-50 px-3 py-2 dark:bg-white/5">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $slot['day'] }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">{{ $slot['time'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No public availability preview yet.</p>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-lg font-semibold text-slate-950 dark:text-white">Ratings</h2>
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        Ratings will appear here once course reviews are available.
                    </p>
                </x-ui.card>

                @if($related->isNotEmpty())
                    <div>
                        <h2 class="mb-4 text-lg font-semibold text-slate-950 dark:text-white">Related Instructors</h2>
                        <div class="space-y-4">
                            @foreach($related as $relatedInstructor)
                                <x-instructor.card :instructor="$relatedInstructor" />
                            @endforeach
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </main>
</div>
@endsection
