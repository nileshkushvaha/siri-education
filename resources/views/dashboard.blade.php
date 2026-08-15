@extends('layouts.frontend')

@section('title', 'Dashboard — ' . config('app.name'))

@section('breadcrumbs')
    <x-frontend.breadcrumb :crumbs="[['label' => 'Dashboard']]" />
@endsection

@section('content')
<div class="min-h-screen bg-surface-dark">

    {{-- ── MAIN CONTENT ────────────────────────────────────────────────── --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        {{-- Flash messages --}}
        @if(session('success'))
            <div class="mb-8 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 p-4 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-emerald-300 text-sm">{{ session('success') }}</p>
            </div>
        @endif

        {{-- Hero Welcome --}}
        <div class="mb-10">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold text-white mb-2">
                        Welcome back,
                        <span class="text-grad">{{ auth()->user()->first_name ?? explode(' ', auth()->user()->name)[0] }}</span>! 👋
                    </h1>
                    <p class="text-slate-400">Ready to continue your learning journey?</p>
                </div>
                <a href="#" class="btn-indigo px-5 py-2.5 rounded-xl text-white text-sm font-semibold flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Browse Courses
                </a>
            </div>
        </div>

        {{-- Stats Grid --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
            @foreach([
                ['My Courses',    '0', 'from-indigo-500 to-violet-500', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', 'indigo'],
                ['Completed',     '0', 'from-emerald-500 to-teal-500',  'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'emerald'],
                ['Hours Learned', '0', 'from-amber-500 to-orange-500',  'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'amber'],
                ['Certificates',  '0', 'from-pink-500 to-rose-500',     'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z', 'pink'],
            ] as [$label, $value, $gradient, $icon, $color])
            <div class="rounded-2xl border border-white/[0.07] p-5 relative overflow-hidden group hover:border-white/[0.12] transition-all"
                 style="background:rgba(255,255,255,0.03)">
                <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500"
                     style="background:radial-gradient(ellipse at top left, rgba(99,102,241,.06), transparent 70%)"></div>
                <div class="relative z-10">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br {{ $gradient }} flex items-center justify-center mb-4 shadow-lg">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $icon }}"/>
                        </svg>
                    </div>
                    <p class="text-3xl font-bold text-white mb-1">{{ $value }}</p>
                    <p class="text-slate-400 text-sm">{{ $label }}</p>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Two-column layout --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Continue Learning + Quick Actions --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Continue Learning --}}
                <div class="rounded-2xl border border-white/[0.07] p-6" style="background:rgba(255,255,255,0.03)">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-white">Continue Learning</h2>
                        <a href="#" class="text-xs text-indigo-400 hover:text-indigo-300 transition">View all →</a>
                    </div>

                    {{-- Empty state --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="w-20 h-20 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 text-indigo-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>
                        <h3 class="text-slate-300 font-semibold mb-2">No courses yet</h3>
                        <p class="text-slate-400 text-sm mb-5 max-w-xs">Start your learning journey by enrolling in your first course.</p>
                        <a href="#" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all shadow-lg shadow-indigo-500/20">
                            Browse Courses
                        </a>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach([
                        ['Browse Courses', 'Discover 500+ courses', 'from-indigo-500 to-violet-500', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
                        ['My Profile',    'Update your details',    'from-violet-500 to-purple-500', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                        ['Find a Tutor',  'Connect with experts',   'from-amber-500 to-orange-500',  'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
                    ] as [$title, $sub, $gradient, $icon])
                    <a href="{{ $title === 'My Profile' ? route('profile.show') : '#' }}"
                       class="rounded-2xl border border-white/[0.07] p-5 hover:border-white/[0.12] transition-all group cursor-pointer"
                       style="background:rgba(255,255,255,0.03)">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br {{ $gradient }} flex items-center justify-center mb-3 shadow-md group-hover:scale-110 transition-transform">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $icon }}"/>
                            </svg>
                        </div>
                        <p class="text-slate-200 font-semibold text-sm mb-0.5">{{ $title }}</p>
                        <p class="text-slate-400 text-xs">{{ $sub }}</p>
                    </a>
                    @endforeach
                </div>
            </div>

            {{-- Right sidebar --}}
            <div class="space-y-6">

                {{-- Profile card --}}
                <div class="rounded-2xl border border-white/[0.07] p-5" style="background:rgba(255,255,255,0.03)">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                            @if(auth()->user()->avatar)
                                <img src="{{ asset('storage/' . auth()->user()->avatar) }}" class="w-full h-full object-cover" alt="">
                            @else
                                <span class="text-white font-bold text-lg">{{ strtoupper(substr(auth()->user()->first_name ?? auth()->user()->name, 0, 1)) }}</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-white font-semibold text-sm">{{ auth()->user()->first_name ? auth()->user()->first_name . ' ' . auth()->user()->last_name : auth()->user()->name }}</p>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <div class="badge-dot w-1.5 h-1.5"></div>
                                <span class="text-emerald-400 text-xs">Active</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 mb-4">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">Profile Complete</span>
                            <span class="text-slate-300 font-medium">30%</span>
                        </div>
                        <div class="h-1.5 bg-white/[0.06] rounded-full overflow-hidden">
                            <div class="h-full w-[30%] bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full"></div>
                        </div>
                        <p class="text-slate-400 text-xs">Add your photo, bio and preferences to complete your profile</p>
                    </div>

                    <a href="{{ route('profile.show') }}"
                       class="block w-full text-center py-2 rounded-xl border border-white/[0.10] text-slate-300 hover:text-white hover:bg-white/[0.05] text-sm font-medium transition-all">
                        Complete Profile
                    </a>
                </div>

                {{-- Streak / Motivation --}}
                <div class="rounded-2xl border border-amber-500/20 p-5 relative overflow-hidden"
                     style="background:linear-gradient(135deg,rgba(245,158,11,.06),rgba(234,88,12,.04))">
                    <div class="absolute top-0 right-0 w-24 h-24 rounded-full bg-amber-500/10 blur-2xl"></div>
                    <div class="relative z-10">
                        <div class="text-3xl mb-2">🔥</div>
                        <p class="text-amber-300 font-bold text-lg">Day 1 Streak!</p>
                        <p class="text-slate-400 text-xs mt-1">Learn every day to build your streak and unlock rewards.</p>
                        <div class="flex items-center gap-1 mt-3">
                            @for($i = 0; $i < 7; $i++)
                                <div class="flex-1 h-1.5 rounded-full {{ $i === 0 ? 'bg-amber-400' : 'bg-white/[0.08]' }}"></div>
                            @endfor
                        </div>
                        <p class="text-slate-400 text-xs mt-2">1 / 7 days this week</p>
                    </div>
                </div>

                {{-- Recent Activity --}}
                <div class="rounded-2xl border border-white/[0.07] p-5" style="background:rgba(255,255,255,0.03)">
                    <h3 class="text-sm font-semibold text-white mb-4">Recent Activity</h3>
                    <div class="flex flex-col items-center justify-center py-6 text-center">
                        <svg class="w-8 h-8 text-slate-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-slate-400 text-xs">No activity yet</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
@endsection
