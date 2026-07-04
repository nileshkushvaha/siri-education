@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Link Expired — ' . config('app.name'))

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden">

    {{-- Background orbs --}}
    <div class="absolute top-[-10rem] left-[-10rem] w-[36rem] h-[36rem] rounded-full bg-amber-600/10 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-10rem] right-[-10rem] w-[36rem] h-[36rem] rounded-full bg-red-600/8 blur-[120px] pointer-events-none"></div>
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(245,158,11,.03) 1px,transparent 1px);background-size:40px 40px;"></div>

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

            {{-- Warning icon --}}
            <div class="relative inline-flex items-center justify-center mb-6">
                <div class="w-20 h-20 rounded-2xl bg-amber-500/10 border border-amber-500/25 flex items-center justify-center">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
            </div>

            <h2 class="text-2xl font-bold text-white mb-2">Verification link expired</h2>
            <p class="text-slate-400 text-sm leading-relaxed mb-6">
                This email verification link has expired or has already been used.
                Verification links are valid for <strong class="text-slate-300">60 minutes</strong> only.
            </p>

            @auth
            {{-- User is logged in — show resend button --}}
            <div class="mb-5 flex items-start gap-3 rounded-xl bg-blue-500/10 border border-blue-500/25 p-4 text-left">
                <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-blue-300 text-sm">Request a new link below and check your inbox.</p>
            </div>

            <form method="POST" action="{{ route('auth.verification.send') }}" class="mb-4">
                @csrf
                <button type="submit" class="auth-btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Send new verification link
                </button>
            </form>

            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="w-full px-5 py-3 rounded-xl border border-white/[0.12] text-slate-400 hover:bg-white/[0.05] hover:text-white transition font-medium text-sm">
                    Sign out
                </button>
            </form>

            @else
            {{-- User is not logged in — show sign in CTA --}}
            <div class="mb-6 flex items-start gap-3 rounded-xl bg-slate-500/10 border border-slate-500/20 p-4 text-left">
                <svg class="w-5 h-5 text-slate-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-slate-400 text-sm">Sign in to your account to request a new verification link.</p>
            </div>

            <a href="{{ route('auth.login') }}" class="auth-btn-primary inline-flex mb-4 no-underline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                Sign in to request new link
            </a>

            <div class="mt-2">
                <a href="{{ route('auth.register') }}" class="text-sm text-slate-400 hover:text-slate-300 transition">
                    Don't have an account? Register →
                </a>
            </div>
            @endauth
        </div>

        <p class="text-center text-xs text-slate-400 mt-5">
            Need help? <a href="#" class="text-slate-400 hover:text-slate-400 transition">Contact support</a>
        </p>
    </div>
</div>
@endsection
