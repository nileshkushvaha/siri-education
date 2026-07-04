@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Unlock Account — ' . config('app.name'))

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden">
    <div class="absolute top-[-10rem] left-[-10rem] w-[38rem] h-[38rem] rounded-full bg-indigo-600/15 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-10rem] right-[-10rem] w-[36rem] h-[36rem] rounded-full bg-violet-600/12 blur-[120px] pointer-events-none"></div>
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(99,102,241,.04) 1px,transparent 1px);background-size:40px 40px;"></div>

    <div class="relative z-10 w-full max-w-md">

        <div class="flex items-center justify-center gap-3 mb-10">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            </div>
            <span class="text-xl font-bold text-white tracking-tight">{{ config('app.name') }}</span>
        </div>

        <div class="auth-card p-8 shadow-2xl shadow-black/40 text-center">

            <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/25 flex items-center justify-center mx-auto mb-6 float-y">
                <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                </svg>
            </div>

            <h2 class="text-2xl font-bold text-white mb-2">Unlock Your Account</h2>
            <p class="text-sm text-slate-400 mb-6 leading-relaxed">
                Click the button below to unlock your account and regain access.
            </p>

            @if($errors->any())
            <div class="mb-5 flex items-start gap-3 rounded-xl bg-red-500/10 border border-red-500/25 p-4 text-left">
                <p class="text-red-300 text-sm">{{ $errors->first('email') }}</p>
            </div>
            @endif

            <form method="POST" action="{{ route('auth.account.unlock.process') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <input type="hidden" name="token" value="{{ request('token') }}">
                <button type="submit" class="auth-btn-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                    </svg>
                    Unlock My Account
                </button>
            </form>

            <p class="mt-6 text-xs text-slate-400">
                This unlock link expires in {{ \App\Models\User::UNLOCK_TOKEN_MINUTES }} minutes.
                <br>Prefer to wait?
                <a href="{{ route('auth.login') }}" class="text-slate-400 hover:text-slate-400 transition">Sign in page</a>
            </p>
        </div>
    </div>
</div>
@endsection
