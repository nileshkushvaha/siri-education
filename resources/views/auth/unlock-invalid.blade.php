@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Invalid Unlock Link — ' . config('app.name'))

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden">
    <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(99,102,241,.04) 1px,transparent 1px);background-size:40px 40px;"></div>

    <div class="relative z-10 w-full max-w-md text-center">

        <div class="w-16 h-16 rounded-2xl bg-red-500/10 border border-red-500/25 flex items-center justify-center mx-auto mb-6">
            <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>

        <h2 class="text-2xl font-bold text-white mb-3">Invalid or Expired Link</h2>
        <p class="text-slate-400 text-sm mb-8 leading-relaxed">
            This account unlock link is invalid or has already expired.<br>
            Your account may have already been unlocked, or the link has expired ({{ \App\Models\User::UNLOCK_TOKEN_MINUTES }} minute window).
        </p>

        <div class="flex flex-col gap-3">
            <a href="{{ route('auth.login') }}" class="auth-btn-primary">Try signing in again →</a>
            <a href="{{ route('auth.password.request') }}" class="text-sm text-slate-400 hover:text-slate-300 transition">
                Reset your password instead
            </a>
        </div>
    </div>
</div>
@endsection
