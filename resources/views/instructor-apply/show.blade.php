@extends('layouts.frontend')

@section('title', 'Become an Instructor — ' . config('app.name'))

@push('meta')
    <meta name="description" content="Share your knowledge with students worldwide. Apply to teach on {{ config('app.name') }} — university students, graduates, professionals and experienced teachers welcome.">
    <meta property="og:title" content="Become an Instructor — {{ config('app.name') }}">
    <meta property="og:description" content="Share your knowledge with students worldwide. Apply to teach with us.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('instructor.apply') }}">
@endpush

@section('page-flash', true)

@php
    $faqs = [
        'eligibility' => [
            'label' => 'Who can apply to teach?',
            'content' => 'University students, graduates, working professionals and experienced teachers can apply. Current school students (up to and including grade 12) are not eligible to apply as instructors.',
        ],
        'documents' => [
            'label' => 'What documents will I need?',
            'content' => 'A government-issued ID, address proof, your highest educational qualification, an optional teaching certificate, and a resume. All documents are stored privately and reviewed only by our verification team.',
        ],
        'timeline' => [
            'label' => 'How long does review take?',
            'content' => 'Review timelines vary based on application volume and completeness. You can track your application status at any time from your dashboard.',
        ],
        'earnings' => [
            'label' => 'How much can I earn?',
            'content' => 'Potential earnings depend on approved lessons, availability, pricing and platform policies. We do not guarantee a fixed income.',
        ],
    ];
@endphp

@section('content')
<div class="bg-surface-dark">

    {{-- ============================================================
         HERO
         ============================================================ --}}
    <section class="relative overflow-hidden border-b border-white/10 bg-slate-950 py-20 sm:py-28">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-400 via-emerald-300 to-fuchsia-400" aria-hidden="true"></div>
        <div class="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <span class="inline-block rounded-full bg-indigo-500/10 px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-indigo-300">Teach With Us</span>
            <h1 class="mt-4 text-4xl font-bold text-white sm:text-5xl">Become an Instructor</h1>
            <p class="mx-auto mt-4 max-w-xl text-lg text-slate-300">
                Share your knowledge with students worldwide. Teach your subjects, set your own availability, and build meaningful learning relationships.
            </p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                @include('instructor-apply.partials.cta', ['size' => 'lg'])
            </div>
        </div>
    </section>

    @if($existingApplication)
        <section class="border-b border-white/10 bg-slate-900 py-10">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <x-ui.alert type="info">
                    <p class="font-semibold">{{ $existingApplication['heading'] }}</p>
                    <p class="mt-1">{{ $existingApplication['message'] }}</p>
                </x-ui.alert>
            </div>
        </section>
    @elseif($eligibility && ! $eligibility->eligible)
        <section class="border-b border-white/10 bg-slate-900 py-10">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <x-ui.alert type="warning">
                    <p>{{ $eligibility->reason }}</p>
                </x-ui.alert>
            </div>
        </section>
    @endif

    {{-- ============================================================
         WHY TEACH WITH US
         ============================================================ --}}
    <section class="bg-white py-20">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <h2 class="text-center text-3xl font-bold text-slate-900">Why Teach With Us?</h2>
            <div class="mt-10 grid gap-6 sm:grid-cols-2">
                @foreach([
                    'Flexible teaching schedule — set your own availability',
                    'Reach students worldwide',
                    'Secure, transparent payments',
                    'Ongoing platform support',
                ] as $benefit)
                    <x-ui.card class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        <span class="text-sm text-slate-700">{{ $benefit }}</span>
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         WHO CAN APPLY
         ============================================================ --}}
    <section class="bg-slate-50 py-20">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <h2 class="text-center text-3xl font-bold text-slate-900">Who Can Apply?</h2>
            <p class="mx-auto mt-3 max-w-2xl text-center text-slate-500">
                Instructor applications are currently available for university students, graduates and professionals.
            </p>
            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach(['University Students', 'Graduates', 'Professionals', 'Experienced Teachers'] as $audience)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                        <span class="text-sm font-semibold text-slate-800">{{ $audience }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         APPLICATION PROCESS
         ============================================================ --}}
    <section class="bg-white py-20" aria-labelledby="application-process-heading">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <h2 id="application-process-heading" class="text-center text-3xl font-bold text-slate-900">Application Process</h2>
            <ol class="mt-10 space-y-6">
                @foreach([
                    'Create your account',
                    'Complete your instructor profile',
                    'Submit verification documents',
                    'Verification review',
                    'Start teaching',
                ] as $index => $step)
                    <li class="flex items-start gap-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold text-white">{{ $index + 1 }}</span>
                        <span class="pt-1.5 text-slate-700">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="mt-8 text-center text-xs text-slate-400">
                Potential earnings depend on approved lessons, availability, pricing and platform policies.
            </p>
        </div>
    </section>

    {{-- ============================================================
         FAQ
         ============================================================ --}}
    <section class="bg-slate-50 py-20">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <h2 class="text-center text-3xl font-bold text-slate-900">Frequently Asked Questions</h2>
            <x-ui.accordion :items="$faqs" class="mt-10" />
        </div>
    </section>

    {{-- ============================================================
         FINAL CTA
         ============================================================ --}}
    <section class="bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 py-20 text-center">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold text-white">Ready to start teaching?</h2>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                @include('instructor-apply.partials.cta', ['size' => 'lg'])
            </div>
        </div>
    </section>

</div>
@endsection
