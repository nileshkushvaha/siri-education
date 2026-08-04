@extends('layouts.frontend')

@section('title', config('app.name').' — Personalised 1-on-1 Learning')

@push('meta')
<meta name="description" content="Find verified instructors, book flexible one-to-one lessons, and follow a personalised learning plan with {{ config('app.name') }}.">
@endpush

@php
$appName ??= config('app.name', 'SIRI Education');
$recentPosts ??= collect();
$featuredSubjects ??= collect();
$featuredFaqs ??= collect();

$subjectPalettes = [
['gradient' => 'from-indigo-500 to-violet-500', 'surface' => 'bg-indigo-50 text-indigo-700'],
['gradient' => 'from-cyan-500 to-blue-500', 'surface' => 'bg-cyan-50 text-cyan-700'],
['gradient' => 'from-rose-500 to-orange-400', 'surface' => 'bg-rose-50 text-rose-700'],
['gradient' => 'from-emerald-500 to-teal-500', 'surface' => 'bg-emerald-50 text-emerald-700'],
['gradient' => 'from-amber-500 to-orange-500', 'surface' => 'bg-amber-50 text-amber-700'],
['gradient' => 'from-fuchsia-500 to-violet-500', 'surface' => 'bg-fuchsia-50 text-fuchsia-700'],
];
@endphp

@section('content')
<main class="overflow-hidden bg-white text-slate-900" data-public-homepage>
    <section class="relative border-b border-indigo-100 bg-gradient-to-br from-[#f8fbff] via-white to-[#fff4ef]">
        <div class="pointer-events-none absolute -left-32 top-10 h-96 w-96 rounded-full bg-cyan-200/35 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-[25%] top-0 h-80 w-80 rounded-full bg-amber-200/25 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-[30rem] w-[30rem] rounded-full bg-violet-200/40 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-30" style="background-image:radial-gradient(#818cf8 .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>

        <div class="relative mx-auto grid max-w-7xl items-center gap-10 px-4 py-10 sm:px-6 sm:py-12 lg:grid-cols-[1.04fr_.96fr] lg:px-8 lg:py-14">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full border border-indigo-300 bg-gradient-to-r from-indigo-50 via-white to-violet-50 px-5 py-2.5 text-sm font-black uppercase tracking-[0.12em] text-indigo-700 shadow-md shadow-indigo-100 ring-2 ring-indigo-100 sm:text-base">
                    <span class="flex -space-x-1" aria-hidden="true">
                        <span class="h-2.5 w-2.5 rounded-full bg-cyan-400"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-fuchsia-500"></span>
                    </span>
                    One-to-one live online tutoring
                </span>

                <h1 class="mt-5 max-w-3xl text-3xl font-black tracking-tight text-slate-950 sm:text-5xl lg:text-[4rem] lg:leading-[1.04]">
                    Find the right instructor.
                    <span class="bg-gradient-to-r from-indigo-600 via-violet-600 to-rose-500 bg-clip-text text-transparent">Make real progress.</span>
                </h1>
                <p class="mt-4 max-w-2xl text-lg leading-8 text-slate-600">
                    Discover verified instructors, book flexible one-to-one lessons, manage homework, and follow a learning plan shaped around your goals.
                </p>

                <div class="mt-6 flex flex-wrap gap-2 sm:flex-nowrap">
                    <a href="{{ route('instructors.index') }}" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-4 text-sm font-black text-white shadow-xl shadow-indigo-200 transition hover:-translate-y-0.5 hover:shadow-violet-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                        Explore instructors
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
                        </svg>
                    </a>
                    <a href="#how-it-works" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white/90 px-4 text-sm font-black text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-cyan-100">
                        See how it works
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7" />
                        </svg>
                    </a>
                    @if(Route::has('booking.create'))
                    <a href="{{ route('booking.create') }}" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-violet-200 bg-violet-50 px-4 text-sm font-black text-violet-700 shadow-sm transition hover:-translate-y-0.5 hover:border-violet-300 hover:bg-violet-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-100">
                        Book a Free Demo
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
                        </svg>
                    </a>
                    @endif
                </div>

                <div class="mt-6 flex flex-wrap gap-x-6 gap-y-3 text-sm font-bold text-slate-600">
                    @foreach(['Verified public profiles', 'Timezone-aware booking', 'Secure wallet and payments'] as $trustPoint)
                    <span class="inline-flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" aria-hidden="true">✓</span>
                        {{ $trustPoint }}
                    </span>
                    @endforeach
                </div>
            </div>

            <div class="relative mx-auto w-full max-w-xl" aria-label="Student learning workspace preview">
                <div class="absolute -inset-7 rounded-[2.5rem] bg-gradient-to-br from-cyan-300/30 via-indigo-300/30 to-rose-300/35 blur-2xl" aria-hidden="true"></div>
                <div class="relative overflow-hidden rounded-[2rem] border border-white bg-white/90 p-5 shadow-2xl shadow-indigo-200/50 backdrop-blur sm:p-6">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-5">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-indigo-600">Your learning workspace</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">Everything in one clear path</h2>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">On track</span>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 p-5 text-white shadow-lg shadow-indigo-200">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wide text-indigo-100">Next lesson</span>
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/15" aria-hidden="true">▶</span>
                            </div>
                            <p class="mt-7 text-lg font-black">Learn at your pace</p>
                            <p class="mt-1 text-sm text-indigo-100">Flexible scheduling in your timezone</p>
                        </div>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-400 text-white" aria-hidden="true">✓</span>
                            <p class="mt-5 text-xs font-bold uppercase tracking-wide text-amber-700">Weekly progress</p>
                            <p class="mt-1 text-lg font-black text-slate-900">Goals made visible</p>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-amber-100">
                                <div class="h-full w-4/5 rounded-full bg-gradient-to-r from-amber-400 to-rose-400"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-cyan-100 bg-cyan-50/70 p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 font-black text-white" aria-hidden="true">1:1</span>
                            <div>
                                <p class="font-black text-slate-900">Find your instructor match</p>
                                <p class="mt-0.5 text-sm text-slate-600">Compare subjects, expertise, profiles, and availability.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-rose-100 bg-rose-50/70 p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-orange-500 text-xl font-black text-white" aria-hidden="true">✓</span>
                            <div>
                                <p class="font-black text-slate-900">Keep every lesson organised</p>
                                <p class="mt-0.5 text-sm text-slate-600">View bookings, homework, learning plans, and progress together.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="relative overflow-hidden border-y border-white/10 bg-[#080b24] py-16 text-white sm:py-20">
        <div class="pointer-events-none absolute -left-40 top-0 h-96 w-96 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-0 top-0 h-[30rem] w-[30rem] rounded-full bg-fuchsia-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#818cf8 .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div class="relative">
                <div class="rounded-[2rem] bg-gradient-to-br from-indigo-500 via-violet-600 to-fuchsia-600 p-7 text-white shadow-2xl shadow-indigo-950/50 ring-1 ring-white/15 sm:p-9">
                    <p class="text-xs font-black uppercase tracking-[0.16em] text-cyan-100">Learning that stays connected</p>
                    <h2 class="mt-3 text-3xl font-black">From first goal to visible progress</h2>
                    <div class="mt-8 space-y-4">
                        @foreach([
                        ['01', 'Set learning goals', 'Capture what you want to achieve and the subjects that matter.'],
                        ['02', 'Book flexible lessons', 'Choose from instructor availability displayed in your timezone.'],
                        ['03', 'Keep learning organised', 'Lessons, homework, plans, notifications, and progress stay together.'],
                        ] as [$number, $title, $description])
                        <div class="flex gap-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/10 backdrop-blur">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-sm font-black text-indigo-700">{{ $number }}</span>
                            <div>
                                <h3 class="font-black">{{ $title }}</h3>
                                <p class="mt-1 text-sm leading-6 text-indigo-100">{{ $description }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="absolute -bottom-6 -right-4 hidden rounded-2xl border border-amber-100 bg-amber-50 p-4 shadow-xl sm:block">
                    <p class="text-xs font-black uppercase text-amber-700">Student-first design</p>
                    <p class="mt-1 font-black text-slate-900">Clear, private, structured</p>
                </div>
            </div>

            <div>
                <p class="text-sm font-black uppercase tracking-[0.15em] text-cyan-300">One connected education workspace</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">Less administrative friction. More meaningful learning.</h2>
                <p class="mt-5 leading-8 text-slate-300">Discovery, booking, lessons, homework, communication, and payment records work together while private information remains protected.</p>
                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    @foreach([
                    ['Flexible scheduling', 'Availability and confirmed lesson times respect your timezone.', 'bg-cyan-400'],
                    ['Verified instructor trust', 'Public profiles reflect approval and visibility rules.', 'bg-emerald-400'],
                    ['Learning continuity', 'Goals, homework, feedback, and plans stay connected.', 'bg-violet-400'],
                    ['Traceable payments', 'Wallet and payment records remain clear and auditable.', 'bg-amber-400'],
                    ] as [$title, $description, $accentClass])
                    <article class="rounded-2xl border border-white/10 bg-white/[0.06] p-5 shadow-lg shadow-black/10 backdrop-blur transition hover:-translate-y-0.5 hover:border-white/20 hover:bg-white/[0.09]">
                        <span class="block h-2 w-10 rounded-full {{ $accentClass }}" aria-hidden="true"></span>
                        <h3 class="mt-4 font-black text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-300">{{ $description }}</p>
                    </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="bg-white py-16 sm:py-20" aria-labelledby="home-process-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-sm font-black uppercase tracking-[0.15em] text-rose-600">How it works</p>
                <h2 id="home-process-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Start learning in four clear steps</h2>
                <p class="mt-4 leading-7 text-slate-600">A straightforward journey from discovery to your ongoing learning plan.</p>
            </div>
            <ol class="relative mt-12 grid gap-5 md:grid-cols-4">
                <div class="absolute left-[12%] right-[12%] top-8 hidden h-px bg-gradient-to-r from-cyan-200 via-indigo-300 to-rose-200 md:block" aria-hidden="true"></div>
                @foreach([
                ['Discover', 'Explore subjects and approved public instructor profiles.', 'from-cyan-500 to-blue-500'],
                ['Compare', 'Review expertise, profile information, and availability.', 'from-indigo-500 to-violet-500'],
                ['Book', 'Select an eligible slot and complete the required checkout.', 'from-violet-500 to-fuchsia-500'],
                ['Progress', 'Follow lessons, homework, goals, feedback, and learning plans.', 'from-rose-500 to-orange-400'],
                ] as $index => [$title, $description, $gradient])
                <li class="relative rounded-3xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br {{ $gradient }} text-lg font-black text-white shadow-lg">{{ $index + 1 }}</span>
                    <h3 class="mt-5 text-lg font-black text-slate-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $description }}</p>
                </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if($featuredSubjects->isNotEmpty())
    <section class="relative isolate overflow-hidden border-y border-white/10 bg-[#050816] py-16 text-white sm:py-20" aria-labelledby="home-subjects-heading">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute -left-24 -top-32 h-96 w-96 rounded-full bg-cyan-500/20 blur-3xl"></div>
            <div class="absolute left-1/2 top-1/3 h-80 w-80 -translate-x-1/2 rounded-full bg-indigo-600/20 blur-3xl"></div>
            <div class="absolute -bottom-40 -right-20 h-[28rem] w-[28rem] rounded-full bg-fuchsia-500/20 blur-3xl"></div>
            <div class="absolute inset-0 opacity-20" style="background-image:linear-gradient(rgba(129,140,248,.18) 1px,transparent 1px),linear-gradient(90deg,rgba(129,140,248,.18) 1px,transparent 1px);background-size:48px 48px;mask-image:linear-gradient(to bottom,black,transparent 85%)"></div>
        </div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <span class="inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-4 py-2 text-xs font-black uppercase tracking-[0.16em] text-cyan-300 backdrop-blur">
                    Explore what you can learn
                </span>
                <h2 id="home-subjects-heading" class="mt-5 text-3xl font-black tracking-tight text-white sm:text-4xl">Subjects with bookable instructors</h2>
                <p class="mt-4 leading-7 text-slate-300">Browse active subjects and connect with instructors who are ready to help you make meaningful progress.</p>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($featuredSubjects as $index => $subject)
                @php $palette = $subjectPalettes[$index % count($subjectPalettes)]; @endphp
                <a href="{{ route('instructors.index', ['subject' => $subject['value']]) }}" class="group relative overflow-hidden rounded-3xl border border-white/10 bg-white/[0.07] p-5 shadow-xl shadow-black/20 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:border-white/25 hover:bg-white/[0.12] hover:shadow-2xl hover:shadow-indigo-950/40 focus:outline-none focus-visible:ring-4 focus-visible:ring-cyan-300/30">
                    <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-gradient-to-br {{ $palette['gradient'] }} opacity-20 blur-2xl transition group-hover:opacity-35" aria-hidden="true"></div>
                    <span class="relative flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $palette['gradient'] }} text-lg font-black text-white shadow-lg shadow-black/20">{{ mb_strtoupper(mb_substr($subject['label'], 0, 1)) }}</span>
                    <h3 class="relative mt-5 font-black text-white transition group-hover:text-cyan-200">{{ $subject['label'] }}</h3>
                    <span class="relative mt-3 inline-flex items-center gap-1 text-sm font-bold text-slate-400 transition group-hover:gap-2 group-hover:text-white">View instructors <span aria-hidden="true">→</span></span>
                </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <section class="border-y border-amber-100 bg-gradient-to-br from-[#fffaf0] via-white to-[#f2fbff] py-16 sm:py-20" aria-labelledby="learning-lifecycle-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-sm font-black uppercase tracking-[0.15em] text-orange-600">Beyond the video call</p>
                    <h2 id="learning-lifecycle-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">A complete learning lifecycle—not isolated lessons</h2>
                    <p class="mt-4 max-w-2xl leading-7 text-slate-600">Each confirmed lesson can connect to preparation, attendance, homework, instructor feedback, progress review, and the student’s longer-term learning plan.</p>
                </div>
                <span class="w-fit rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-emerald-700">Structured for continuity</span>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach([
                ['Live lesson readiness', 'Meeting access, reminders, and attendance support keep confirmed lessons organised.', 'from-cyan-500 to-blue-500', 'bg-cyan-50 border-cyan-100'],
                ['Homework workflow', 'Assignments, submissions, due dates, and instructor feedback stay connected to learning.', 'from-violet-500 to-fuchsia-500', 'bg-violet-50 border-violet-100'],
                ['Personal learning plans', 'Goals, milestones, instructor guidance, and progress reviews create a longer-term path.', 'from-indigo-500 to-violet-500', 'bg-indigo-50 border-indigo-100'],
                ['Quality feedback', 'Eligible lesson feedback supports student improvement and instructor quality assurance.', 'from-rose-500 to-orange-400', 'bg-rose-50 border-rose-100'],
                ['Timely notifications', 'Booking, lesson, homework, payment, and account events can trigger relevant reminders.', 'from-amber-500 to-orange-500', 'bg-amber-50 border-amber-100'],
                ['Financial clarity', 'Wallet entries, payments, refunds, and statements remain traceable rather than hidden.', 'from-emerald-500 to-teal-500', 'bg-emerald-50 border-emerald-100'],
                ] as $index => [$title, $description, $gradient, $surface])
                <article class="rounded-3xl border {{ $surface }} p-6 transition hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $gradient }} text-sm font-black text-white shadow-lg">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    <h3 class="mt-5 text-lg font-black text-slate-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $description }}</p>
                </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="relative overflow-hidden border-y border-indigo-100 bg-gradient-to-br from-indigo-50 via-white to-rose-50 py-16 sm:py-20" aria-labelledby="home-instructors-heading">
        <div class="pointer-events-none absolute -left-28 top-12 h-80 w-80 rounded-full bg-cyan-200/35 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-fuchsia-200/30 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#818cf8 .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div class="max-w-3xl">
                    <span class="inline-flex rounded-full border border-indigo-200 bg-white/80 px-4 py-2 text-xs font-black uppercase tracking-[0.15em] text-indigo-600 shadow-sm backdrop-blur">Verified expertise · Student favourites</span>
                    <h2 id="home-instructors-heading" class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Meet instructors students trust</h2>
                    <p class="mt-4 max-w-2xl leading-7 text-slate-600">Explore approved public profiles, from marketplace-featured educators to instructors ranked by genuine student reviews.</p>
                </div>
                <a href="{{ route('instructors.index') }}" class="inline-flex min-h-11 w-fit shrink-0 items-center rounded-xl border border-indigo-200 bg-white px-5 text-sm font-black text-indigo-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-indigo-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">Explore all instructors →</a>
            </div>

            <div class="mt-10 space-y-10">
                <livewire:frontend.cms.featured-teachers
                    eyebrow="Featured"
                    title="Selected for marketplace visibility"
                    description="Approved profiles highlighted for their expertise and teaching readiness."
                    :limit="4"
                    :columns="4"
                    :embedded="true" />

                <livewire:frontend.cms.featured-teachers
                    eyebrow="Loved by students"
                    title="Popular instructors"
                    description="Ranked by review volume and average rating — never random, never sponsored."
                    :limit="4"
                    :columns="4"
                    section="popular"
                    :embedded="true" />
            </div>
        </div>
    </section>

    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <article class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-indigo-600 to-violet-700 p-8 text-white shadow-xl shadow-indigo-200 sm:p-10">
                <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-cyan-300/20 blur-2xl" aria-hidden="true"></div>
                <p class="relative text-xs font-black uppercase tracking-[0.16em] text-indigo-100">For students</p>
                <h2 class="relative mt-3 text-3xl font-black">Build your learning path</h2>
                <p class="relative mt-4 max-w-lg leading-7 text-indigo-100">Create an account, set your goals, discover instructors, and keep every lesson connected.</p>
                <a href="{{ route('auth.register') }}" class="relative mt-7 inline-flex min-h-12 items-center rounded-xl bg-white px-5 text-sm font-black text-indigo-700 transition hover:-translate-y-0.5">Create student account →</a>
            </article>
            <article class="relative overflow-hidden rounded-[2rem] border border-rose-100 bg-gradient-to-br from-amber-50 via-rose-50 to-violet-50 p-8 shadow-xl shadow-rose-100 sm:p-10">
                <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-rose-300/25 blur-2xl" aria-hidden="true"></div>
                <p class="relative text-xs font-black uppercase tracking-[0.16em] text-rose-600">For instructors</p>
                <h2 class="relative mt-3 text-3xl font-black text-slate-950">Turn expertise into progress</h2>
                <p class="relative mt-4 max-w-lg leading-7 text-slate-600">Apply, complete verification, publish your approved expertise, and manage your teaching workspace.</p>
                <a href="{{ route('instructor.apply') }}" class="relative mt-7 inline-flex min-h-12 items-center rounded-xl bg-gradient-to-r from-orange-500 via-rose-500 to-violet-600 px-5 text-sm font-black text-white shadow-lg shadow-rose-200 transition hover:-translate-y-0.5">Become an instructor →</a>
            </article>
        </div>
    </section>

    <section class="relative overflow-hidden bg-[#0b102b] py-16 text-white sm:py-20" aria-labelledby="home-trust-heading">
        <div class="pointer-events-none absolute left-0 top-0 h-80 w-80 rounded-full bg-emerald-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-0 bottom-0 h-96 w-96 rounded-full bg-violet-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-sm font-black uppercase tracking-[0.15em] text-emerald-300">Trust is a platform feature</p>
                <h2 id="home-trust-heading" class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">Designed for educational, identity, and financial trust</h2>
                <p class="mt-4 leading-7 text-slate-300">The SRS treats trust as an operational requirement: verification, access control, privacy, traceability, and accountable platform actions work together.</p>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach([
                ['Instructor verification', 'Teaching access is separated from registration and depends on the configured review lifecycle.', 'text-cyan-300', 'border-cyan-400/20 bg-cyan-400/[0.07]'],
                ['Privacy by design', 'Student records and private verification documents remain limited to authorised access.', 'text-fuchsia-300', 'border-fuchsia-400/20 bg-fuchsia-400/[0.07]'],
                ['Traceable finances', 'Wallet transactions, payments, refunds, earnings, and settlements retain auditable records.', 'text-amber-300', 'border-amber-400/20 bg-amber-400/[0.07]'],
                ['Quality accountability', 'Reviews, feedback, status changes, and administrative actions follow controlled rules.', 'text-emerald-300', 'border-emerald-400/20 bg-emerald-400/[0.07]'],
                ] as $index => [$title, $description, $accent, $surface])
                <article class="rounded-3xl border {{ $surface }} p-6 backdrop-blur">
                    <span class="text-xs font-black uppercase tracking-[0.15em] {{ $accent }}">Trust layer {{ $index + 1 }}</span>
                    <h3 class="mt-4 text-lg font-black text-white">{{ $title }}</h3>
                    <p class="mt-3 text-sm leading-6 text-slate-300">{{ $description }}</p>
                </article>
                @endforeach
            </div>
        </div>
    </section>

    @if($featuredFaqs->isNotEmpty())
    <section class="relative overflow-hidden border-y border-violet-100 bg-gradient-to-br from-violet-50 via-white to-cyan-50 py-16 sm:py-20" aria-labelledby="home-faq-heading">
        <div class="pointer-events-none absolute -left-24 top-16 h-72 w-72 rounded-full bg-cyan-200/35 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-violet-200/40 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <span class="inline-flex rounded-full border border-indigo-200 bg-white/80 px-4 py-2 text-xs font-black uppercase tracking-[0.15em] text-indigo-600 shadow-sm backdrop-blur">Frequently asked questions</span>
                    <h2 id="home-faq-heading" class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Understand the platform before you begin</h2>
                    <p class="mt-4 max-w-2xl leading-7 text-slate-600">Get clear answers about registration, instructor approval, lesson bookings, payments, and the safeguards that support every learning relationship.</p>
                </div>
                <a href="{{ route('faqs.index') }}" class="inline-flex min-h-11 w-fit shrink-0 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                    Browse all help topics <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="mt-8 grid gap-3 md:grid-cols-3">
                    @foreach([
                    ['For students', 'Know what to compare before choosing an instructor or confirming a lesson.', 'bg-indigo-50 text-indigo-700', 'S'],
                    ['For instructors', 'Understand application, verification, profile visibility, and teaching readiness.', 'bg-fuchsia-50 text-fuchsia-700', 'I'],
                    ['For every account', 'Learn how privacy, payments, cancellations, and support processes work.', 'bg-cyan-50 text-cyan-700', '?'],
                    ] as [$title, $description, $surface, $symbol])
                    <article class="flex gap-4 rounded-2xl border border-white/80 bg-white/75 p-5 shadow-lg shadow-indigo-100/30 backdrop-blur">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $surface }} text-sm font-black ring-1 ring-current/10" aria-hidden="true">{{ $symbol }}</span>
                        <div>
                            <h3 class="font-black text-slate-900">{{ $title }}</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</p>
                        </div>
                    </article>
                    @endforeach
            </div>

            <div class="mt-8 grid items-start gap-4 md:grid-cols-2">
                @foreach($featuredFaqs as $faq)
                <details class="group rounded-2xl border border-white/80 bg-white/90 p-5 shadow-lg shadow-indigo-100/40 backdrop-blur open:border-indigo-200 open:shadow-xl">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-black text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">
                        {{ $faq->question }}
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-50 to-violet-100 text-indigo-600 ring-1 ring-indigo-100 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <div class="mt-4 border-t border-indigo-50 pt-4 pr-10 text-sm leading-7 text-slate-600">{!! Illuminate\Support\Str::markdown($faq->answer, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                </details>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    @if($recentPosts->isNotEmpty())
    <section class="bg-[#f8fbff] py-16 sm:py-20" aria-labelledby="home-articles-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-black uppercase tracking-[0.15em] text-cyan-700">Learning resources</p>
                    <h2 id="home-articles-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Latest articles</h2>
                </div>
                <a href="{{ route('blog.index') }}" class="text-sm font-black text-indigo-600 hover:text-indigo-500">View all articles →</a>
            </div>
            <div class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach($recentPosts as $index => $post)
                @php $postImage = $post->featured_image_url; @endphp
                <article class="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-indigo-100/60">
                    <a href="{{ route('blog.show', $post->slug) }}" class="block focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-indigo-100">
                        <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br {{ ['from-indigo-500 to-violet-600','from-cyan-500 to-blue-600','from-rose-500 to-orange-400'][$index % 3] }}">
                            @if($postImage)
                            <img src="{{ $postImage }}" alt="" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                            @else
                            <div class="flex h-full items-center justify-center text-4xl font-black text-white/80" aria-hidden="true">{{ mb_substr($post->title, 0, 1) }}</div>
                            @endif
                        </div>
                        <div class="p-6">
                            <p class="text-xs font-bold uppercase tracking-wide text-indigo-600">{{ $post->published_at?->format('M j, Y') ?? 'Article' }}</p>
                            <h3 class="mt-3 text-lg font-black leading-7 text-slate-900 transition group-hover:text-indigo-700">{{ $post->title }}</h3>
                            @if($post->excerpt)<p class="mt-3 text-sm leading-6 text-slate-600">{{ Str::limit(strip_tags($post->excerpt), 125) }}</p>@endif
                        </div>
                    </a>
                </article>
                @endforeach
            </div>
        </div>
    </section>
    @endif

</main>
@endsection
