@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Verify Your Email — ' . config('app.name'))

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden">

    {{-- Background orbs --}}
    <div class="absolute top-[-10rem] left-[-10rem] w-[38rem] h-[38rem] rounded-full bg-indigo-600/15 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-10rem] right-[-10rem] w-[36rem] h-[36rem] rounded-full bg-violet-600/12 blur-[120px] pointer-events-none"></div>
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(99,102,241,.04) 1px,transparent 1px);background-size:40px 40px;"></div>

    <div class="relative z-10 w-full max-w-md">

        {{-- Logo --}}
        <div class="flex items-center justify-center mb-10">
            <a href="{{ route('home') }}" class="rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"><x-ui.brand-logo variant="dark" class="block h-12 w-auto" /></a>
        </div>

        {{-- Card --}}
        <div class="auth-card p-8 shadow-2xl shadow-black/40 text-center">

            {{-- Animated envelope --}}
            <div class="relative inline-flex items-center justify-center mb-6">
                <div class="w-20 h-20 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center float-y">
                    <svg class="w-10 h-10 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                {{-- Pulsing ring --}}
                <div class="absolute inset-0 rounded-2xl border border-indigo-500/25 animate-ping opacity-30"></div>
            </div>

            <h2 class="text-2xl font-bold text-white mb-2">Verify your email</h2>
            <p class="text-slate-400 text-sm leading-relaxed mb-2">
                We sent a 6-digit verification code to
            </p>
            <p class="text-indigo-400 font-semibold text-sm mb-6">
                {{ $pendingUser->email }}
            </p>


            {{-- Tips box --}}
            <div class="glass rounded-xl p-4 text-left mb-6 space-y-2">
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Didn't receive it?</p>
                @foreach(["Check your spam or junk folder", "Allow a few minutes for delivery", "Ensure the email address is correct"] as $tip)
                <div class="flex items-center gap-2.5">
                    <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 flex-shrink-0"></div>
                    <p class="text-slate-400 text-xs">{{ $tip }}</p>
                </div>
                @endforeach
            </div>

            <livewire:frontend.auth.verify-email-notice />

            {{-- Leave this account --}}
            @auth
            <form method="POST" action="{{ route('auth.logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="text-sm text-slate-400 hover:text-slate-300 transition">
                    Sign out and use a different account
                </button>
            </form>
            @else
            <a href="{{ route('auth.login') }}" class="mt-4 inline-block text-sm text-slate-400 hover:text-slate-300 transition">
                Use a different account
            </a>
            @endauth
        </div>

        <p class="text-center text-xs text-slate-400 mt-5">
            Need help? <a href="#" class="text-slate-400 hover:text-slate-400 transition">Contact support</a>
        </p>
    </div>
</div>
@endsection
