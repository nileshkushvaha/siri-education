@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Set New Password — ' . config('app.name'))

@section('content')
    <div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden">

    {{-- Background orbs --}}
    <div class="absolute top-[-12rem] left-[-12rem] w-[40rem] h-[40rem] rounded-full bg-indigo-600/15 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-12rem] right-[-12rem] w-[40rem] h-[40rem] rounded-full bg-violet-600/12 blur-[120px] pointer-events-none"></div>
    <div class="absolute top-[40%] right-[20%] w-[20rem] h-[20rem] rounded-full bg-blue-600/8 blur-[100px] pointer-events-none"></div>
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(99,102,241,.04) 1px,transparent 1px);background-size:40px 40px;"></div>

    <div class="relative z-10 w-full max-w-md">

        {{-- Logo --}}
        <div class="flex items-center justify-center gap-3 mb-10">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            </div>
            <span class="text-xl font-bold text-white tracking-tight">{{ config('app.name') }}</span>
        </div>

        {{-- Card --}}
        <div class="auth-card p-8 shadow-2xl shadow-black/40">

            {{-- Icon + heading --}}
            <div class="text-center mb-8">
                <div class="inline-flex w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-1">Set new password</h2>
                <p class="text-slate-400 text-sm">Create a strong password to secure your account</p>
            </div>


            <livewire:frontend.auth.reset-password-form :token="$token" :email="$email ?? ''" />

            <div class="mt-5 text-center">
                <a href="{{ route('auth.login') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-300 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to sign in
                </a>
            </div>
        </div>

        {{-- Security note --}}
        <p class="text-center text-xs text-slate-400 mt-5">
            After resetting, you'll be automatically signed in to your account.
        </p>
    </div>
</div>
@endsection
