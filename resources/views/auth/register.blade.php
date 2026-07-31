@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Create Your Account — ' . config('app.name'))
@section('meta_description', 'Create your ' . config('app.name') . ' account to connect with instructors, manage your learning, and access personalized education tools.')

@php
    $generalSettings = app(\App\Settings\GeneralSettings::class);
    $appBadgeLetter = mb_substr($generalSettings->app_short_name ?: $generalSettings->app_name, 0, 1);
@endphp

@section('content')
<main class="min-h-screen bg-slate-950 text-white lg:grid lg:grid-cols-[minmax(0,0.9fr)_minmax(32rem,1.1fr)]">
    <section class="relative hidden overflow-hidden border-r border-white/[0.08] lg:block" aria-labelledby="registration-benefits-title">
        <div class="sticky top-0 flex min-h-screen flex-col overflow-hidden p-10 xl:p-12">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(59,130,246,.18),transparent_28%),radial-gradient(circle_at_88%_72%,rgba(168,85,247,.17),transparent_30%),linear-gradient(145deg,rgba(79,70,229,.20),rgb(2,6,23)_46%,rgba(8,145,178,.09))]" aria-hidden="true"></div>
        <div class="absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-cyan-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="absolute -right-24 bottom-16 h-80 w-80 rounded-full bg-fuchsia-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-1 flex-col">
            <div class="flex items-center justify-between gap-5">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500 font-black">{{ $appBadgeLetter }}</span>
                    <span class="text-lg font-bold">{{ config('app.name') }}</span>
                </a>
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website <span class="ml-1" aria-hidden="true">↗</span></a>
            </div>

            <div class="mt-10 max-w-xl xl:mt-12">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-300">Create your account</p>
                <h2 id="registration-benefits-title" class="mt-3 text-4xl font-bold leading-tight xl:text-5xl">One account to <span class="bg-gradient-to-r from-cyan-300 via-indigo-300 to-fuchsia-300 bg-clip-text text-transparent">learn, teach, and grow.</span></h2>
                <p class="mt-4 max-w-lg text-base leading-relaxed text-slate-300">Access personalized learning, verified instructors, flexible lessons, and your complete education workspace.</p>
            </div>

            {{-- Code-native product preview keeps the panel useful without a large decorative bitmap. --}}
            <div class="relative mt-8 max-w-xl overflow-hidden rounded-3xl border border-indigo-300/15 bg-gradient-to-br from-indigo-500/[0.12] via-white/[0.045] to-fuchsia-500/[0.10] p-4 shadow-2xl shadow-indigo-950/40 backdrop-blur-xl xl:p-5" aria-label="Learning workspace preview">
                <div class="absolute -right-10 -top-12 h-32 w-32 rounded-full bg-cyan-400/10 blur-2xl" aria-hidden="true"></div>
                <div class="flex items-center justify-between border-b border-white/[0.08] pb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Your education workspace</p>
                        <p class="mt-1 text-sm font-semibold text-white">Everything you need in one place</p>
                    </div>
                    <span class="rounded-full border border-emerald-300/15 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300">On track</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-cyan-300/10 bg-gradient-to-br from-cyan-500/[0.08] to-slate-950/40 p-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/15 text-cyan-300" aria-hidden="true">▶</div>
                        <p class="mt-4 text-xs text-slate-400">Next lesson</p>
                        <p class="mt-1 text-sm font-semibold text-white">Mathematics</p>
                        <p class="mt-1 text-xs text-slate-400">Tomorrow · 4:00 PM</p>
                    </div>
                    <div class="rounded-2xl border border-fuchsia-300/10 bg-gradient-to-br from-fuchsia-500/[0.08] to-slate-950/40 p-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-fuchsia-400/15 text-fuchsia-300" aria-hidden="true">✓</div>
                        <p class="mt-4 text-xs text-slate-400">Weekly progress</p>
                        <p class="mt-1 text-sm font-semibold text-white">4 of 5 goals</p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10"><div class="h-full w-4/5 rounded-full bg-gradient-to-r from-cyan-400 via-indigo-400 to-fuchsia-400"></div></div>
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-3 rounded-2xl border border-amber-300/10 bg-gradient-to-r from-amber-500/[0.07] to-slate-950/35 p-3.5">
                    <div class="flex -space-x-2" aria-hidden="true">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-indigo-500 text-[10px] font-bold">AK</span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-violet-500 text-[10px] font-bold">JM</span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-slate-900 bg-emerald-600 text-[10px] font-bold">SL</span>
                    </div>
                    <div><p class="text-xs font-semibold text-white">Find the right tutor</p><p class="mt-0.5 text-xs text-slate-400">Explore by subject, level, and availability</p></div>
                </div>
            </div>

            <div class="mt-6 grid max-w-xl grid-cols-3 gap-3" aria-label="Platform benefits">
                <div class="rounded-2xl border border-cyan-300/10 bg-cyan-400/[0.06] p-3.5"><span class="text-lg text-cyan-300" aria-hidden="true">⌁</span><p class="mt-2 text-xs font-semibold text-white">Flexible scheduling</p><p class="mt-1 text-[11px] leading-4 text-slate-400">Across your timezone</p></div>
                <div class="rounded-2xl border border-amber-300/10 bg-amber-400/[0.06] p-3.5"><span class="text-lg text-amber-300" aria-hidden="true">◆</span><p class="mt-2 text-xs font-semibold text-white">Verified experts</p><p class="mt-1 text-[11px] leading-4 text-slate-400">Learn with confidence</p></div>
                <div class="rounded-2xl border border-fuchsia-300/10 bg-fuchsia-400/[0.06] p-3.5"><span class="text-lg text-fuchsia-300" aria-hidden="true">↗</span><p class="mt-2 text-xs font-semibold text-white">Visible progress</p><p class="mt-1 text-[11px] leading-4 text-slate-400">Goals in one place</p></div>
            </div>

            <div class="mt-5 grid max-w-xl gap-3 sm:grid-cols-2">
                <div class="flex items-center gap-3 rounded-2xl border border-indigo-300/10 bg-indigo-400/[0.06] p-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-400/15 text-indigo-200" aria-hidden="true">S</span><div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">For students</p><p class="mt-1 text-sm text-slate-300">Book lessons and follow your learning plan.</p></div></div>
                <div class="flex items-center gap-3 rounded-2xl border border-fuchsia-300/10 bg-fuchsia-400/[0.06] p-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-fuchsia-400/15 text-fuchsia-200" aria-hidden="true">I</span><div><p class="text-xs font-semibold uppercase tracking-wider text-fuchsia-300">For instructors</p><p class="mt-1 text-sm text-slate-300">Teach, manage lessons, and grow your reach.</p></div></div>
            </div>

            <div class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
                <div class="group relative overflow-hidden rounded-2xl border border-emerald-300/10 bg-gradient-to-br from-emerald-400/[0.09] to-cyan-400/[0.035] p-4">
                    <div class="absolute -right-6 -top-7 h-20 w-20 rounded-full bg-emerald-300/10 blur-xl" aria-hidden="true"></div>
                    <div class="relative flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-400/15 text-emerald-200" aria-hidden="true">◇</span>
                        <div><p class="text-sm font-semibold text-white">Secure by design</p><p class="mt-1 text-xs leading-5 text-slate-400">Protected accounts, payments, wallet, and booking history.</p></div>
                    </div>
                </div>
                <div class="group relative overflow-hidden rounded-2xl border border-rose-300/10 bg-gradient-to-br from-rose-400/[0.09] to-orange-400/[0.035] p-4">
                    <div class="absolute -right-6 -top-7 h-20 w-20 rounded-full bg-rose-300/10 blur-xl" aria-hidden="true"></div>
                    <div class="relative flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-400/15 text-rose-200" aria-hidden="true">◎</span>
                        <div><p class="text-sm font-semibold text-white">Learning stays connected</p><p class="mt-1 text-xs leading-5 text-slate-400">Lessons, homework, feedback, and progress in one place.</p></div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </section>

    <section class="relative flex min-h-screen items-start justify-center bg-[radial-gradient(circle_at_80%_10%,rgba(139,92,246,.10),transparent_28%)] px-4 py-4 sm:px-8 lg:px-10 lg:py-5" aria-labelledby="registration-form-title">
        <div class="w-full max-w-xl">
            <div class="mb-4 flex items-center justify-between lg:hidden">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500">{{ $appBadgeLetter }}</span>{{ config('app.name') }}
                </a>
                <a href="{{ route('home') }}" class="ml-auto inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website</a>
            </div>

            <div class="rounded-3xl border border-indigo-300/[0.12] bg-gradient-to-br from-white/[0.055] via-white/[0.03] to-violet-500/[0.045] p-5 shadow-2xl shadow-indigo-950/25 sm:p-6">
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
