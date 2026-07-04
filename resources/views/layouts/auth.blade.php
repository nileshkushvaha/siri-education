{{--
    Authentication layout — split-panel chrome for new sign-in/sign-up-
    style pages. Extends layouts.frontend in "bare" mode (no navbar,
    footer, or flash-message block) and reuses the existing .auth-*
    CSS primitives already defined in resources/css/app.css — this
    layout does not introduce new styling, only reusable structure.

    Existing pages under resources/views/auth/ (login, register, etc.)
    predate this layout and hand-roll the same markup inline; they are
    left untouched. New authentication-style pages should extend this
    instead of duplicating that structure.

    Usage:
        @extends('layouts.auth')
        @section('title', 'Sign In')

        @section('auth-title', 'Welcome back!')
        @section('auth-subtitle', 'Continue where you left off.')
        @section('auth-features')
            {{-- optional list of <x-ui.badge> or feature bullets --}}
        @endsection

        @section('auth-content')
            <div class="auth-card p-8">
                {{-- form goes here --}}
            </div>
        @endsection
--}}
@extends('layouts.frontend')

@section('bare', true)

@section('content')
<div class="auth-page">

    {{-- ── Left decorative panel — hidden below lg, see .auth-left-panel ── --}}
    <div class="auth-left-panel justify-between p-10 xl:p-14">
        <div class="bg-orb w-[28rem] h-[28rem] bg-indigo-600/20 top-[-8rem] left-[-8rem]" aria-hidden="true"></div>
        <div class="bg-orb w-[22rem] h-[22rem] bg-violet-600/15 bottom-[-6rem] right-[-6rem]" style="animation-delay:3s" aria-hidden="true"></div>

        <div class="relative z-10">
            <a href="{{ route('home') }}" class="flex items-center gap-3 mb-16 group w-fit">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 transition-shadow">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </div>
                <span class="text-xl font-bold text-white tracking-tight">{{ config('app.name') }}</span>
            </a>

            <div class="mb-12">
                <h1 class="text-4xl xl:text-5xl font-bold text-white leading-tight mb-4">
                    @yield('auth-title', 'Welcome')
                </h1>
                <p class="text-slate-400 text-lg leading-relaxed">
                    @yield('auth-subtitle', '')
                </p>
            </div>

            @hasSection('auth-features')
                <div class="space-y-5">
                    @yield('auth-features')
                </div>
            @endif
        </div>

        <p class="relative z-10 text-slate-400 text-xs">&copy; {{ now()->year }} {{ config('app.name') }}</p>
    </div>

    {{-- ── Right panel — the form card ─────────────────────────────────── --}}
    <div class="auth-right-panel">
        <div class="w-full max-w-md">
            @yield('auth-content')
        </div>
    </div>
</div>
@endsection
