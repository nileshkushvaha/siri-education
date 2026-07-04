@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Create Account — ' . config('app.name'))

@section('content')
<div class="auth-page">

    {{-- ── LEFT DECORATIVE PANEL ───────────────────────────────────────── --}}
    <div class="auth-left-panel justify-between p-10 xl:p-14">

        <div class="bg-orb w-[30rem] h-[30rem] bg-violet-600/20 top-[-10rem] right-[-8rem]"></div>
        <div class="bg-orb w-[24rem] h-[24rem] bg-indigo-600/15 bottom-[-8rem] left-[-6rem]" style="animation-delay:4s"></div>
        <div class="bg-orb w-[14rem] h-[14rem] bg-purple-500/10 top-[35%] left-[15%]" style="animation-delay:7s"></div>
        <div class="absolute inset-0 pointer-events-none" style="background-image:radial-gradient(circle,rgba(139,92,246,.07) 1px,transparent 1px);background-size:36px 36px;"></div>

        <div class="relative z-10">
            {{-- Left panel logo → links to home --}}
            <a href="{{ route('home') }}" class="flex items-center gap-3 mb-14 group w-fit">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 transition-shadow">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </div>
                <span class="text-xl font-bold text-white tracking-tight">{{ config('app.name') }}</span>
            </a>

            {{-- Student count badge --}}
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-500/10 border border-emerald-500/25 mb-6">
                <span class="badge-dot"></span>
                <span class="text-emerald-400 text-sm font-medium">10,000+ active students</span>
            </div>

            <h1 class="text-4xl xl:text-5xl font-bold text-white leading-tight mb-4">
                Start learning<br><span class="text-grad">for free</span> today
            </h1>
            <p class="text-slate-400 text-lg leading-relaxed mb-12">
                Join thousands of students already mastering new skills on {{ config('app.name') }}.
            </p>

            <div class="space-y-5">
                @foreach([
                    ['icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'title' => 'Free to get started', 'desc' => 'No credit card required for basic access'],
                    ['icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10', 'title' => '500+ Courses', 'desc' => 'From beginner to advanced level'],
                    ['icon' => 'M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z', 'title' => '24/7 Tutor Support', 'desc' => 'Get help whenever you need it'],
                    ['icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'title' => 'Verified Certificates', 'desc' => 'Shareable credentials for your career'],
                ] as $feat)
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-9 h-9 rounded-xl bg-violet-500/15 border border-violet-500/25 flex items-center justify-center">
                        <svg class="w-4.5 h-4.5 text-violet-400 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $feat['icon'] }}"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-white font-semibold text-sm">{{ $feat['title'] }}</p>
                        <p class="text-slate-400 text-xs mt-0.5">{{ $feat['desc'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Stats row --}}
        <div class="relative z-10 mt-10 grid grid-cols-3 gap-4">
            @foreach([['10K+', 'Students'], ['500+', 'Courses'], ['98%', 'Satisfaction']] as $stat)
            <div class="text-center p-3 rounded-xl bg-white/[0.04] border border-white/[0.07]">
                <p class="text-xl font-bold text-white">{{ $stat[0] }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ $stat[1] }}</p>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── RIGHT FORM PANEL ────────────────────────────────────────────── --}}
    <div class="auth-right-panel">
        <div class="absolute top-0 right-0 w-72 h-72 bg-violet-600/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-72 h-72 bg-indigo-600/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 w-full max-w-md py-6">

            {{-- Mobile: logo + back to home --}}
            <div class="flex items-center justify-between mb-8 lg:hidden">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <span class="text-xl font-bold text-white">{{ config('app.name') }}</span>
                </a>
                <a href="{{ route('home') }}" class="text-xs text-slate-400 hover:text-slate-300 transition-colors flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Home
                </a>
            </div>

            {{-- Desktop: back to home --}}
            <div class="hidden lg:flex justify-end mb-6">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-300 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back to home
                </a>
            </div>

            <div class="mb-8">
                <h2 class="text-3xl font-bold text-white mb-2">Create account</h2>
                <p class="text-slate-400 text-sm">Already have one? <a href="{{ route('auth.login') }}" class="text-indigo-400 hover:text-indigo-300 font-medium transition">Sign in →</a></p>
            </div>

            <livewire:frontend.auth.register-form />
        </div>
    </div>
</div>
@endsection
