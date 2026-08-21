@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Sign In — ' . config('app.name'))

@php
$authSettings = app(\App\Settings\AuthenticationSettings::class);
$regSettings = app(\App\Settings\RegistrationSettings::class);
$generalSettings = app(\App\Settings\GeneralSettings::class);
$appBadgeLetter = mb_substr($generalSettings->app_short_name ?: $generalSettings->app_name, 0, 1);
@endphp

@section('content')
<main class="min-h-screen bg-slate-950 text-white lg:grid lg:grid-cols-[minmax(0,0.92fr)_minmax(30rem,1.08fr)]">
    <section class="relative hidden min-h-screen overflow-hidden border-r border-white/[0.08] lg:block" aria-labelledby="login-benefits-title">
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/20 via-slate-950 to-violet-600/10" aria-hidden="true"></div>
        <div class="absolute -left-28 top-1/3 h-80 w-80 rounded-full bg-indigo-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="absolute -right-24 bottom-10 h-80 w-80 rounded-full bg-violet-500/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex min-h-screen flex-col p-8 xl:p-10">
            <div class="flex items-center justify-between gap-5">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500 font-black">{{ $appBadgeLetter }}</span>
                    <span class="text-lg font-bold">{{ config('app.name') }}</span>
                </a>
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website <span class="ml-1" aria-hidden="true">↗</span></a>
            </div>

            <div class="mt-16 max-w-xl xl:mt-20">
                <h2 id="login-benefits-title" class="text-4xl font-bold leading-snug xl:text-4xl">Pick up your learning journey where you left it.</h2>
                <p class="mt-3 max-w-lg text-sm leading-relaxed text-slate-300 xl:text-base">Your classes, homework, learning goals, progress, and account tools are ready when you sign in.</p>
            </div>

            <div class="mt-7 max-w-xl rounded-3xl border border-white/10 bg-white/[0.055] p-5 shadow-2xl shadow-black/30 backdrop-blur-xl" aria-label="Student workspace preview">
                <div class="flex items-center justify-between border-b border-white/[0.08] pb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Your workspace</p>
                        <p class="mt-1 text-sm font-semibold">Today at a glance</p>
                    </div>
                    <span class="rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300">Ready to learn</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-white/[0.07] bg-slate-950/35 p-4">
                        <p class="text-xs text-slate-400">Class schedule</p>
                        <p class="mt-2 text-sm font-semibold">Your next class</p>
                        <p class="mt-2 text-xs text-slate-400">View timing and joining details</p>
                    </div>
                    <div class="rounded-2xl border border-white/[0.07] bg-slate-950/35 p-4">
                        <p class="text-xs text-slate-400">Learning progress</p>
                        <p class="mt-2 text-sm font-semibold">Weekly goals</p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full w-4/5 rounded-full bg-gradient-to-r from-indigo-400 to-violet-400"></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">Stay consistent and keep moving</p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs text-slate-300">
                    <div class="rounded-xl bg-white/[0.04] px-2 py-3">Homework</div>
                    <div class="rounded-xl bg-white/[0.04] px-2 py-3">Messages</div>
                    <div class="rounded-xl bg-white/[0.04] px-2 py-3">Wallet</div>
                </div>
            </div>

            <ul class="mt-6 grid max-w-xl grid-cols-2 gap-x-5 gap-y-2 text-sm text-slate-300">
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Authoritative class schedule</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Private homework and feedback</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Learning goals and milestones</span></li>
                <li class="flex gap-2.5"><span class="text-emerald-300" aria-hidden="true">✓</span><span>Country-aware wallet and billing</span></li>
            </ul>
        </div>
    </section>

    <section class="relative flex min-h-screen items-center justify-center px-4 py-5 sm:px-8 lg:px-10" aria-labelledby="login-form-title">
        <div class="w-full max-w-lg">
            <div class="mb-5 flex items-center justify-between lg:hidden">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500">{{ $appBadgeLetter }}</span>{{ config('app.name') }}
                </a>
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Explore website</a>
            </div>

            <div class="rounded-3xl border border-white/[0.09] bg-white/[0.035] p-5 shadow-2xl shadow-black/20 sm:p-7">
                <div class="mb-5">
                    <h1 id="login-form-title" class="text-2xl font-bold">Sign in to your account</h1>
                    <p class="mt-1.5 text-sm leading-snug text-slate-400">Use the email and password associated with your learning account.</p>
                </div>

                @if(session('success'))
                <div class="mb-5 rounded-xl border border-emerald-500/25 bg-emerald-500/10 p-4 text-sm text-emerald-300" role="status">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                <div class="mb-5 rounded-xl border border-red-500/25 bg-red-500/10 p-4 text-sm text-red-300" role="alert">{{ session('error') }}</div>
                @endif

                @if(! $authSettings->login_enabled)
                <div class="rounded-xl border border-amber-500/25 bg-amber-500/10 p-5" role="alert">
                    <p class="text-sm font-semibold text-amber-300">Login temporarily unavailable</p>
                    <p class="mt-1 text-xs text-amber-300/80">We’re performing maintenance. Please check back shortly.</p>
                </div>
                @else
                <livewire:frontend.auth.login-form />
                @endif

                @if($regSettings->self_registration_enabled)
                <div class="my-6 flex items-center gap-3" aria-hidden="true">
                    <div class="h-px flex-1 bg-white/[0.07]"></div><span class="text-xs text-slate-400">NEW HERE?</span>
                    <div class="h-px flex-1 bg-white/[0.07]"></div>
                </div>
                <a href="{{ route('auth.register') }}" class="flex min-h-11 w-full items-center justify-center rounded-xl border border-white/[0.12] px-5 text-sm font-semibold text-slate-300 transition hover:bg-white/[0.05] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">Create a free account</a>
                @endif

                <p class="mt-6 text-center text-xs leading-relaxed text-slate-400">By signing in, you agree to our <a href="{{ url('/terms-and-conditions') }}" class="text-indigo-300 hover:text-indigo-200">Terms</a> and <a href="{{ url('/privacy-policy') }}" class="text-indigo-300 hover:text-indigo-200">Privacy Policy</a>.</p>
            </div>
        </div>
    </section>
</main>
@endsection