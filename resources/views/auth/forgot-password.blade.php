@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Reset Password — ' . config('app.name'))

@section('content')
<div class="auth-page">

    {{-- ── LEFT PANEL ──────────────────────────────────────────────────── --}}
    <div class="auth-left-panel justify-between p-10 xl:p-14">
        <div class="bg-orb w-[26rem] h-[26rem] bg-indigo-600/18 top-[-8rem] left-[-8rem]"></div>
        <div class="bg-orb w-[20rem] h-[20rem] bg-violet-600/12 bottom-[-6rem] right-[-4rem]" style="animation-delay:5s"></div>
        <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(99,102,241,.06) 1px,transparent 1px);background-size:36px 36px;"></div>

        <div class="relative z-10">
            <a href="{{ route('home') }}" class="flex items-center mb-16 w-fit rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                <x-ui.brand-logo variant="dark" class="block h-12 w-auto" />
            </a>

            <h1 class="text-4xl xl:text-5xl font-bold text-white leading-tight mb-4">
                Reset in<br>2 easy steps
            </h1>
            <p class="text-slate-400 text-lg leading-relaxed mb-12">
                Regain access to your account quickly and securely.
            </p>

            {{-- Steps --}}
            <div class="space-y-0">
                <div class="flex gap-4">
                    <div class="flex flex-col items-center">
                        <div class="w-9 h-9 rounded-full bg-indigo-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">1</div>
                        <div class="w-0.5 h-14 bg-gradient-to-b from-indigo-500/60 to-transparent mt-1"></div>
                    </div>
                    <div class="pt-1.5 pb-8">
                        <p class="text-white font-semibold">Enter your email address</p>
                        <p class="text-slate-400 text-sm mt-1">We'll send a secure reset link to your inbox</p>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex flex-col items-center">
                        <div class="w-9 h-9 rounded-full bg-white/10 border border-white/15 flex items-center justify-center text-slate-400 font-bold text-sm flex-shrink-0">2</div>
                    </div>
                    <div class="pt-1.5">
                        <p class="text-slate-300 font-semibold">Click the link in your email</p>
                        <p class="text-slate-400 text-sm mt-1">You'll be taken to create a new secure password</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="relative z-10 mt-8">
            <div class="glass-dark rounded-2xl p-5 flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-amber-500/15 border border-amber-500/25 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">Link valid for 60 minutes</p>
                    <p class="text-slate-400 text-xs mt-0.5">For your security, reset links expire after 1 hour. Request a new one if needed.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ── RIGHT PANEL ─────────────────────────────────────────────────── --}}
    <div class="auth-right-panel">
        <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-600/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-violet-600/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 w-full max-w-md">

            {{-- Mobile logo --}}
            <div class="flex items-center justify-center mb-8 lg:hidden">
                <a href="{{ route('home') }}" class="rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"><x-ui.brand-logo variant="dark" class="block h-11 w-auto" /></a>
            </div>

            <livewire:frontend.auth.forgot-password-form />
        </div>
    </div>
</div>
@endsection
