@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Email Verified! — ' . config('app.name'))

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden"
     x-data="{
    countdown: 5,
    init() {
        const interval = setInterval(() => {
            this.countdown--;
            if (this.countdown <= 0) {
                clearInterval(interval);
                window.location.href = '{{ route('dashboard') }}';
            }
        }, 1000);
    }
}">

    {{-- Background orbs --}}
    <div class="absolute top-[-10rem] left-[-10rem] w-[38rem] h-[38rem] rounded-full bg-emerald-600/12 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-10rem] right-[-10rem] w-[36rem] h-[36rem] rounded-full bg-indigo-600/12 blur-[120px] pointer-events-none"></div>
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(16,185,129,.04) 1px,transparent 1px);background-size:40px 40px;"></div>

    <div class="relative z-10 w-full max-w-md text-center">

        {{-- Logo --}}
        <div class="flex items-center justify-center gap-3 mb-10">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            </div>
            <span class="text-xl font-bold text-white tracking-tight">{{ config('app.name') }}</span>
        </div>

        {{-- Card --}}
        <div class="auth-card p-8 md:p-10 shadow-2xl shadow-black/40">

            {{-- Checkmark animation --}}
            <div class="relative inline-flex items-center justify-center mb-6">
                <div class="w-24 h-24 rounded-full bg-emerald-500/10 border border-emerald-500/25 flex items-center justify-center">
                    <svg class="w-12 h-12 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="animation: bounce-in .6s ease forwards;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" fill="rgba(16,185,129,0.1)"/>
                    </svg>
                </div>
                <div class="absolute inset-0 rounded-full border border-emerald-500/20 animate-ping opacity-25"></div>
            </div>

            <h2 class="text-3xl font-bold text-white mb-1">Email Verified! 🎉</h2>
            <p class="text-slate-400 text-sm leading-relaxed mb-6">
                Your account has been successfully verified. Welcome to {{ config('app.name') }}!
            </p>

            {{-- Countdown --}}
            <div class="mb-6 glass rounded-xl p-4">
                <p class="text-slate-400 text-sm">
                    Redirecting to your dashboard in
                    <span class="text-indigo-400 font-bold text-lg mx-1" x-text="countdown"></span>
                    seconds…
                </p>
                {{-- Progress bar --}}
                <div class="mt-3 h-1.5 bg-white/8 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full transition-all duration-1000"
                         :style="`width: ${(1 - countdown / 5) * 100}%`"></div>
                </div>
            </div>

            <a href="{{ route('dashboard') }}"
               class="auth-btn-primary inline-flex no-underline">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Go to Dashboard now
            </a>
        </div>

        <p class="text-center text-xs text-slate-400 mt-5">
            You're all set to start your learning journey!
        </p>
    </div>
</div>
@endsection
