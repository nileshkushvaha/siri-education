@extends('layouts.frontend')

@section('title', 'Become an Instructor — ' . config('app.name'))

@push('meta')
    <meta name="description" content="Share your knowledge with students worldwide. Apply to teach on {{ config('app.name') }} — university students, graduates, professionals and experienced teachers welcome.">
    <meta property="og:title" content="Become an Instructor — {{ config('app.name') }}">
    <meta property="og:description" content="Build a trusted teaching profile, set your availability, and help students make meaningful progress.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('instructor.apply') }}">
@endpush

@section('page-flash', true)

@php
    $benefits = [
        ['title' => 'Teach on your schedule', 'text' => 'Set your availability and keep control of when you teach.', 'color' => 'indigo'],
        ['title' => 'Reach the right students', 'text' => 'Get discovered by learners searching for your approved subjects.', 'color' => 'violet'],
        ['title' => 'Build trusted expertise', 'text' => 'Create a verified profile supported by genuine student reviews.', 'color' => 'emerald'],
        ['title' => 'Manage everything together', 'text' => 'Organise lessons, homework, progress, earnings, and availability.', 'color' => 'amber'],
    ];
@endphp

@section('content')
<div class="overflow-hidden bg-[#f8faff] text-slate-900" data-instructor-application-page>
    <section class="relative border-b border-rose-100 bg-gradient-to-br from-[#fffaf0] via-[#fff7f5] to-[#f5efff]">
        <div class="pointer-events-none absolute -left-24 top-16 h-80 w-80 rounded-full bg-amber-200/45 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-[34%] top-4 h-72 w-72 rounded-full bg-rose-200/35 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-24 top-10 h-96 w-96 rounded-full bg-violet-200/40 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.32]" style="background-image:radial-gradient(#fb7185 0.7px,transparent 0.7px);background-size:22px 22px" aria-hidden="true"></div>

        <div class="relative mx-auto grid max-w-7xl items-start gap-10 px-4 py-10 sm:px-6 sm:py-12 lg:grid-cols-[1.02fr_.98fr] lg:gap-14 lg:px-8 lg:py-14">
            <div class="lg:pt-2">
                <span class="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-white/85 px-4 py-2 text-xs font-bold uppercase tracking-[0.16em] text-rose-700 shadow-sm shadow-rose-100">
                    <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    Teach with {{ config('app.name') }}
                </span>
                <h1 class="mt-5 max-w-2xl text-4xl font-black tracking-tight text-slate-950 sm:text-5xl lg:text-[3.5rem] lg:leading-[1.06]">
                    Turn your expertise into
                    <span class="bg-gradient-to-r from-orange-500 via-rose-500 to-violet-600 bg-clip-text text-transparent">student progress.</span>
                </h1>
                <p class="mt-5 max-w-xl text-lg leading-8 text-slate-600">
                    Teach subjects you know well, choose your availability, and grow a trusted professional profile on one secure learning platform.
                </p>

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    @include('instructor-apply.partials.cta', ['size' => 'lg'])
                    <a href="#application-process" class="inline-flex min-h-12 items-center gap-2 rounded-xl border border-rose-200 bg-white/90 px-5 text-sm font-bold text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-100">
                        See how it works
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
                    </a>
                </div>

                <div class="mt-7 flex max-w-xl flex-wrap gap-x-5 gap-y-3 text-sm">
                    @foreach(['Verified profiles', 'Flexible schedules', 'Secure earnings'] as $point)
                        <div class="inline-flex items-center gap-2 font-bold text-slate-600">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100" aria-hidden="true">
                                <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m5 12 4 4L19 6"/></svg>
                            </span>
                            {{ $point }}
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="relative mx-auto w-full max-w-xl" aria-label="Instructor application overview">
                <div class="absolute -inset-5 rounded-[2rem] bg-gradient-to-br from-amber-300/35 via-rose-300/30 to-violet-300/35 blur-2xl" aria-hidden="true"></div>
                <div class="relative overflow-hidden rounded-[2rem] border border-rose-100 bg-white/92 p-5 shadow-2xl shadow-rose-200/50 backdrop-blur sm:p-6">
                    <div class="flex items-center justify-between gap-4 border-b border-rose-100 pb-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-rose-600">Your teaching journey</p>
                            <h2 class="mt-1 text-xl font-extrabold text-slate-950">A clear path to approval</h2>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-400 via-rose-500 to-violet-600 text-white shadow-lg shadow-rose-200" aria-hidden="true">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.75a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9ZM19.5 12a7.5 7.5 0 0 1-15 0m15 0V8.25m0 3.75h-3.75"/></svg>
                        </span>
                    </div>

                    <ol class="mt-5 space-y-3">
                        @foreach([
                            ['Account and profile', 'Tell us who you are and what you teach.'],
                            ['Qualifications and documents', 'Submit the evidence required for verification.'],
                            ['Review and decision', 'Track progress and respond to document requests.'],
                            ['Teaching readiness', 'Set subjects, availability, and complete your profile.'],
                        ] as $index => [$title, $description])
                            <li class="flex gap-3 rounded-2xl border border-rose-100/80 bg-gradient-to-r from-white to-rose-50/50 p-3.5">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $index === 0 ? 'bg-gradient-to-br from-orange-400 to-rose-500 text-white shadow-md shadow-rose-200' : 'bg-white text-rose-600 ring-1 ring-rose-200' }} text-sm font-extrabold">{{ $index + 1 }}</span>
                                <div>
                                    <p class="font-bold text-slate-800">{{ $title }}</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-500">{{ $description }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div class="mt-4 flex items-start gap-3 rounded-2xl bg-emerald-50 p-3.5 text-sm leading-6 text-emerald-800">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        Your private verification documents are visible only to authorised reviewers.
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if($existingApplication || ($eligibility && ! $eligibility->eligible))
        <section class="bg-white py-8" aria-label="Application status">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <div class="flex items-start gap-4 rounded-2xl border {{ $existingApplication ? 'border-indigo-200 bg-indigo-50 text-indigo-900' : 'border-amber-200 bg-amber-50 text-amber-900' }} p-5 shadow-sm">
                    <svg class="mt-0.5 h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11.25 11.25 12 10.5m0 0 .75-.75M12 10.5v5.25m9-3.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <div>
                        @if($existingApplication)
                            <p class="font-extrabold">{{ $existingApplication['heading'] }}</p>
                            <p class="mt-1 text-sm leading-6 opacity-80">{{ $existingApplication['message'] }}</p>
                        @else
                            <p class="font-extrabold">Application eligibility</p>
                            <p class="mt-1 text-sm leading-6 opacity-80">{{ $eligibility->reason }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section class="bg-white py-16 sm:py-20" aria-labelledby="benefits-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-bold uppercase tracking-[0.16em] text-indigo-600">Built for serious educators</p>
                <h2 id="benefits-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Everything you need to teach with confidence</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">Focus on great teaching while the platform supports discovery, scheduling, learning activity, and transparent financial records.</p>
            </div>

            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($benefits as $index => $benefit)
                    @php $palettes = ['indigo' => 'from-indigo-500 to-blue-500 shadow-indigo-100', 'violet' => 'from-violet-500 to-fuchsia-500 shadow-violet-100', 'emerald' => 'from-emerald-500 to-teal-500 shadow-emerald-100', 'amber' => 'from-amber-500 to-orange-500 shadow-amber-100']; @endphp
                    <article class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-100/60">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $palettes[$benefit['color']] }} text-lg font-black text-white shadow-lg">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="mt-5 text-lg font-extrabold text-slate-900">{{ $benefit['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $benefit['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="border-y border-indigo-100 bg-gradient-to-br from-indigo-50 via-white to-cyan-50 py-16 sm:py-20">
        <div class="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div>
                <p class="text-sm font-bold uppercase tracking-[0.16em] text-indigo-600">Eligibility</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950">Who can apply?</h2>
                <p class="mt-4 max-w-xl leading-7 text-slate-600">Applications are open to adults with meaningful subject knowledge and the ability to support students responsibly.</p>
                <div class="mt-8 grid gap-3 sm:grid-cols-2">
                    @foreach(['University students', 'Graduates', 'Working professionals', 'Experienced teachers'] as $audience)
                        <div class="flex items-center gap-3 rounded-2xl border border-white bg-white/80 p-4 font-bold text-slate-700 shadow-sm">
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700" aria-hidden="true">✓</span>
                            {{ $audience }}
                        </div>
                    @endforeach
                </div>
                <p class="mt-5 flex items-start gap-2 text-sm leading-6 text-slate-500">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 4.5h.008v.008H12V16.5Z"/></svg>
                    Current school students up to and including grade 12 cannot apply as instructors.
                </p>
            </div>

            <div class="rounded-3xl border border-indigo-100 bg-white p-6 shadow-xl shadow-indigo-100/50 sm:p-8">
                <p class="text-sm font-bold uppercase tracking-[0.16em] text-violet-600">Trust and verification</p>
                <h2 class="mt-3 text-2xl font-black text-slate-950">What you may need</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">Exact requirements are configurable and shown inside your application.</p>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach(['Government-issued ID', 'Profile photograph', 'Address verification', 'Education qualifications', 'Professional certificates', 'Resume or introduction video'] as $document)
                        <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-700">
                            <svg class="h-5 w-5 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.25 3.75v3h3M9.75 12h4.5M9.75 15h4.5"/></svg>
                            {{ $document }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="application-process" class="bg-white py-16 sm:py-20" aria-labelledby="application-process-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-bold uppercase tracking-[0.16em] text-indigo-600">Application Process</p>
                <h2 id="application-process-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">From account to teaching readiness</h2>
                <p class="mt-4 leading-7 text-slate-600">Your application remains a draft until required information is complete. Teaching access is enabled only after approval.</p>
            </div>

            <ol class="relative mt-14 grid gap-5 md:grid-cols-5">
                <div class="absolute left-[10%] right-[10%] top-7 hidden h-px bg-gradient-to-r from-indigo-200 via-violet-300 to-emerald-200 md:block" aria-hidden="true"></div>
                @foreach([
                    ['Create account', 'Register and verify your email.'],
                    ['Build profile', 'Add expertise and professional details.'],
                    ['Upload evidence', 'Submit required private documents.'],
                    ['Complete review', 'Respond to requests and track status.'],
                    ['Get ready', 'Configure subjects and availability after approval.'],
                ] as $index => [$step, $description])
                    <li class="relative rounded-2xl border border-slate-200 bg-white p-5 text-center shadow-sm">
                        <span class="relative mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-lg font-black text-white shadow-lg shadow-indigo-200">{{ $index + 1 }}</span>
                        <h3 class="mt-5 font-extrabold text-slate-900">{{ $step }}</h3>
                        <p class="mt-2 text-sm leading-5 text-slate-500">{{ $description }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if($faqs->isNotEmpty())
        <section class="bg-slate-50 py-16 sm:py-20" aria-labelledby="instructor-faq-heading">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
                <div>
                    <p class="text-sm font-bold uppercase tracking-[0.16em] text-indigo-600">Common questions</p>
                    <h2 id="instructor-faq-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950">Apply with clarity</h2>
                    <p class="mt-4 leading-7 text-slate-600">Understand eligibility, verification, review, and earning expectations before you begin.</p>
                </div>
                <div class="space-y-3">
                    @foreach($faqs as $faq)
                        <details class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm open:border-indigo-200 open:shadow-md">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-extrabold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">
                                {{ $faq->question }}
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 transition group-open:rotate-45" aria-hidden="true">+</span>
                            </summary>
                            <p class="mt-4 pr-10 text-sm leading-7 text-slate-600">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="relative overflow-hidden bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 py-16 text-center sm:py-20">
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(white 0.7px,transparent 0.7px);background-size:20px 20px" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-[0.18em] text-indigo-100">Teach with purpose</p>
            <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">Ready to build your instructor profile?</h2>
            <p class="mx-auto mt-4 max-w-xl leading-7 text-indigo-100">Start your application, complete each section at your pace, and track the verification decision from your dashboard.</p>
            <div class="mt-8 flex justify-center">
                @include('instructor-apply.partials.cta', ['size' => 'lg'])
            </div>
            <p class="mt-5 text-xs leading-5 text-indigo-100/80">Potential earnings depend on approved lessons, availability, pricing, student demand, and platform policies. Fixed income is not guaranteed.</p>
        </div>
    </section>
</div>
@endsection
