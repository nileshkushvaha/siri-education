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
    @include('components.blocks.hero-carousel', ['use_default_slides' => true])

    {{-- Why study with us — light section directly under the hero --}}
    <section class="bg-white py-16 sm:py-20" aria-labelledby="home-why-study-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-sm font-black uppercase tracking-[0.15em] text-rose-600">Why us</p>
                <h2 id="home-why-study-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Why study with us?</h2>
                <p class="mt-4 leading-7 text-slate-600">Four reasons students make faster progress with one-to-one learning.</p>
            </div>

            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach([
                [
                'bg-violet-100',
                'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
                'Proven way to improve marks',
                [
                'The National Bureau of Economic Research found one-on-one tutoring produces higher academic gains than group or classroom instruction.',
                'Students tutored one-on-one in maths and reading showed improvements of 30% in test scores.',
                ],
                ],
                [
                'bg-amber-100',
                'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
                'Personalised attention',
                [
                'One-to-one premium classes with expert tutors, shaped around how each student actually learns.',
                'Tutors resolve doubts as they come up and support homework in the same session.',
                ],
                ],
                [
                'bg-emerald-100',
                'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
                'Subject specific courses',
                [
                'PISA 2018 reported that around 1 in 4 students fall below the baseline in at least one of the three core subjects.',
                $appName.' offers subject-specific tutoring, paced to each student’s proficiency.',
                ],
                ],
                [
                'bg-rose-100',
                'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
                'Dedicated student account managers',
                [
                'A named account manager keeps track of attendance, progress, and lesson quality.',
                'One point of contact for scheduling changes, feedback, and escalations.',
                ],
                ],
                ] as [$surface, $iconPath, $cardTitle, $cardPoints])
                <article data-why-card class="flex h-full flex-col rounded-3xl {{ $surface }} p-6">
                    <span data-why-icon class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-sm" aria-hidden="true">
                        <svg class="h-7 w-7 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}" />
                        </svg>
                    </span>
                    <h3 data-why-title class="mt-6 text-lg font-black leading-6 text-slate-900">{{ $cardTitle }}</h3>
                    <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                        @foreach($cardPoints as $cardPoint)
                        <li class="flex gap-2.5">
                            <span data-why-dot style="--why-dot-index: {{ $loop->index }}" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-orange-400" aria-hidden="true"></span>
                            <span>{{ $cardPoint }}</span>
                        </li>
                        @endforeach
                    </ul>
                </article>
                @endforeach
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
                    <p class="mt-4 max-w-2xl leading-7 text-slate-600">Explore approved public profiles, ranked by genuine student reviews.</p>
                </div>
                <a href="{{ route('instructors.index') }}" class="inline-flex min-h-11 w-fit shrink-0 items-center rounded-xl border border-indigo-200 bg-white px-5 text-sm font-black text-indigo-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-indigo-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">Explore all instructors →</a>
            </div>

            <div class="mt-10 space-y-10">
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

    <section class="relative overflow-hidden bg-gradient-to-br from-[#080b24] via-[#151044] to-[#312e81] py-16 text-white sm:py-20" aria-labelledby="home-trust-heading">
        <div class="pointer-events-none absolute left-1/3 top-0 h-80 w-80 rounded-full bg-fuchsia-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-0 bottom-0 h-96 w-96 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-4xl text-center">
                <p class="inline-flex rounded-full border border-cyan-300/35 bg-cyan-400/10 px-5 py-2 text-xs font-black uppercase tracking-[0.22em] text-cyan-300">Built on trust · SIRI Education</p>
                <h2 id="home-trust-heading" class="mt-6 text-3xl font-black tracking-tight text-white sm:text-4xl lg:text-5xl">Trust at every step of <span class="bg-gradient-to-r from-cyan-300 via-violet-300 to-fuchsia-300 bg-clip-text text-transparent">the learning journey.</span></h2>
                <p class="mx-auto mt-5 max-w-3xl text-base leading-7 text-slate-300 sm:text-lg">Verified instructors, protected student information, secure payments, and traceable platform actions create a safer place to learn and teach.</p>
            </div>

            <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach([
                ['◎', '10,000+', 'Students taught'],
                ['✓', '1.5 Lac+', 'Teaching hours'],
                ['◷', '10+', 'Years of learning'],
                ['⌖', '99%', 'Positive feedback'],
                ['▤', '100+', 'Subjects covered'],
                ] as [$icon, $value, $label])
                <article class="flex min-h-56 flex-col items-center justify-center rounded-3xl border border-white/15 bg-white/[0.06] p-6 text-center shadow-xl shadow-slate-950/10 backdrop-blur-sm transition hover:-translate-y-1 hover:border-cyan-300/35 hover:bg-white/[0.09]">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-violet-300/40 bg-violet-400/15 text-lg font-black text-cyan-300" aria-hidden="true">{{ $icon }}</span>
                    <strong class="mt-6 bg-gradient-to-r from-white to-violet-200 bg-clip-text text-3xl font-black tracking-tight text-transparent">{{ $value }}</strong>
                    <span class="mt-2 text-xs font-black uppercase tracking-[0.12em] text-slate-300">{{ $label }}</span>
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
