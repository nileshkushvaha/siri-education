@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Create Your Account — ' . config('app.name'))
@section('meta_description', 'Create your ' . config('app.name') . ' account to connect with instructors, manage your learning, and access personalized education tools.')

@section('content')
<main class="min-h-screen bg-slate-950 text-white lg:grid lg:grid-cols-[minmax(0,0.9fr)_minmax(32rem,1.1fr)]">
    <section class="relative hidden overflow-hidden border-r border-white/[0.08] lg:block" aria-labelledby="registration-benefits-title">
        <div class="sticky top-0 flex min-h-screen flex-col overflow-hidden p-10 xl:p-14">
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/20 via-slate-950 to-violet-600/10" aria-hidden="true"></div>
        <div class="absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-indigo-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="absolute -right-24 bottom-16 h-80 w-80 rounded-full bg-violet-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-1 flex-col">
            <div class="flex items-center justify-between gap-5">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500 font-black">{{ mb_substr(config('app.name'), 0, 1) }}</span>
                    <span class="text-lg font-bold">{{ config('app.name') }}</span>
                </a>
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website <span class="ml-1" aria-hidden="true">↗</span></a>
            </div>

            <div class="mt-12 max-w-xl xl:mt-16">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-300">Create your account</p>
                <h2 id="registration-benefits-title" class="mt-3 text-4xl font-bold leading-tight xl:text-5xl">One account to learn, teach, and grow.</h2>
                <p class="mt-4 max-w-lg text-base leading-relaxed text-slate-300 xl:text-lg">Create one secure account to access personalized learning, connect with instructors, manage your lessons, and grow with {{ config('app.name') }}.</p>
            </div>

            {{-- Code-native product preview keeps the panel useful without a large decorative bitmap. --}}
            <div class="relative mt-9 max-w-xl rounded-3xl border border-white/10 bg-white/[0.055] p-4 shadow-2xl shadow-black/30 backdrop-blur-xl xl:mt-12 xl:p-5" aria-label="Learning workspace preview">
                <div class="flex items-center justify-between border-b border-white/[0.08] pb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Your education workspace</p>
                        <p class="mt-1 text-sm font-semibold text-white">Everything you need in one place</p>
                    </div>
                    <span class="rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300">On track</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-white/[0.07] bg-slate-950/35 p-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500/15 text-indigo-300" aria-hidden="true">▶</div>
                        <p class="mt-4 text-xs text-slate-400">Next lesson</p>
                        <p class="mt-1 text-sm font-semibold text-white">Mathematics</p>
                        <p class="mt-1 text-xs text-slate-400">Tomorrow · 4:00 PM</p>
                    </div>
                    <div class="rounded-2xl border border-white/[0.07] bg-slate-950/35 p-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-500/15 text-violet-300" aria-hidden="true">✓</div>
                        <p class="mt-4 text-xs text-slate-400">Weekly progress</p>
                        <p class="mt-1 text-sm font-semibold text-white">4 of 5 goals</p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10"><div class="h-full w-4/5 rounded-full bg-gradient-to-r from-indigo-400 to-violet-400"></div></div>
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-3 rounded-2xl border border-white/[0.07] bg-slate-950/35 p-3.5">
                    <div class="flex -space-x-2" aria-hidden="true">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-indigo-500 text-[10px] font-bold">AK</span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-violet-500 text-[10px] font-bold">JM</span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-emerald-600 text-[10px] font-bold">SL</span>
                    </div>
                    <div><p class="text-xs font-semibold text-white">Find the right tutor</p><p class="mt-0.5 text-xs text-slate-400">Explore by subject, level, and availability</p></div>
                </div>
            </div>

            <ul class="mt-7 grid max-w-xl gap-x-5 gap-y-3 text-sm text-slate-300 xl:grid-cols-2 xl:mt-8">
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Personalized learning experience based on your goals</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Connect with verified instructors worldwide</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Flexible scheduling across your timezone</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Lessons, homework, and progress tracking in one place</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Secure payments, wallet, and booking history</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>A single account for learning and teaching</span></li>
            </ul>

            <div class="mt-9 grid max-w-xl gap-4 sm:grid-cols-2 xl:mt-10">
                <div class="rounded-2xl border border-white/[0.07] bg-white/[0.03] p-4">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-indigo-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-indigo-400" aria-hidden="true"></span>
                        For students
                    </p>
                    <ul class="mt-3 space-y-2.5 text-sm text-slate-300">
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Discover and book verified instructors by subject</span></li>
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Follow a structured learning plan at your own pace</span></li>
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Keep homework, lessons, and progress in one dashboard</span></li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-white/[0.07] bg-white/[0.03] p-4">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-violet-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-violet-400" aria-hidden="true"></span>
                        For instructors
                    </p>
                    <ul class="mt-3 space-y-2.5 text-sm text-slate-300">
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Get discovered by students searching your subjects</span></li>
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Manage your schedule, lessons, and earnings in one place</span></li>
                        <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Build your reputation with verified student reviews</span></li>
                    </ul>
                </div>
            </div>
        </div>
        </div>
    </section>

    <section class="relative flex min-h-screen items-start justify-center px-4 py-4 sm:px-8 lg:px-10 lg:py-5" aria-labelledby="registration-form-title">
        <div class="w-full max-w-xl">
            <div class="mb-4 flex items-center justify-between lg:hidden">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500">{{ mb_substr(config('app.name'), 0, 1) }}</span>{{ config('app.name') }}
                </a>
                <a href="{{ route('home') }}" class="ml-auto inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website</a>
            </div>

            <div class="rounded-3xl border border-white/[0.09] bg-white/[0.035] p-5 shadow-2xl shadow-black/20 sm:p-6">
                <div class="mb-5">
                    <p class="text-sm font-semibold text-indigo-300">Free account</p>
                    <h1 id="registration-form-title" class="mt-1 text-3xl font-bold">Create your account</h1>
                    <p class="mt-2 text-sm leading-relaxed text-slate-400">Only essential details now. Complete your profile after signing in.</p>
                </div>
                <livewire:frontend.auth.register-form />
            </div>
        </div>
    </section>
</main>
@endsection
