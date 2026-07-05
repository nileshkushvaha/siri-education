@extends('layouts.account')

@section('title', 'Dashboard — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[['label' => 'Dashboard']]" />
@endsection

@section('account-content')

    {{-- ── Page Header ──────────────────────────────────────────────── --}}
    <x-account.page-header
        :date="now()->format('l, F j, Y')"
        :name="auth()->user()->first_name ?? explode(' ', auth()->user()->name)[0]"
    />

    {{-- ── Two-column layout ────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Overview stats + Next Classes + Quick Actions --}}
        <div class="lg:col-span-2 space-y-6">
            @if(auth()->user()->hasRole('student') || ! auth()->user()->hasRole('instructor'))
                <livewire:frontend.student.dashboard-overview />
            @else
                <livewire:frontend.instructor.dashboard-overview />
            @endif
        </div>

        {{-- Right sidebar --}}
        <div class="space-y-6">

            {{-- Profile card --}}
            <x-account.card>
                <x-account.profile-header :summary="$accountProfileSummary" variant="compact">
                    <x-slot:actions>
                        <a href="{{ route('profile.show') }}"
                           class="block w-full text-center py-2 rounded-xl border border-white/[0.10] text-slate-300 hover:text-white hover:bg-white/[0.05] text-sm font-medium transition-all">
                            Complete Profile
                        </a>
                    </x-slot:actions>
                </x-account.profile-header>
            </x-account.card>

            @if(auth()->user()->hasRole('student') || ! auth()->user()->hasRole('instructor'))
                <x-account.card>
                    <div class="space-y-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-sm font-semibold text-white">Instructor Onboarding</h2>
                                <p class="text-xs text-slate-400 mt-1">
                                    {{ $instructorOnboarding['status']?->label() ?? 'Not started' }}
                                </p>
                            </div>
                            <span class="text-sm font-semibold text-indigo-300">{{ $instructorOnboarding['percentage'] }}%</span>
                        </div>

                        <div class="h-2 rounded-full bg-white/[0.06] overflow-hidden">
                            <div class="h-full rounded-full bg-indigo-500" style="width: {{ $instructorOnboarding['percentage'] }}%"></div>
                        </div>

                        @if($instructorOnboarding['missing'])
                            <div class="flex flex-wrap gap-2">
                                @foreach(array_slice($instructorOnboarding['missing'], 0, 4) as $missing)
                                    <span class="px-2 py-1 rounded-lg bg-white/[0.04] text-xs text-slate-300">{{ $missing }}</span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('dashboard.instructor.start') }}" class="flex-1">
                                @csrf
                                <button type="submit" class="w-full py-2 rounded-xl border border-white/[0.10] text-slate-300 hover:text-white hover:bg-white/[0.05] text-sm font-medium transition-all">
                                    {{ $instructorOnboarding['status'] ? 'Continue' : 'Become Instructor' }}
                                </button>
                            </form>

                            @if($instructorOnboarding['status'] && $instructorOnboarding['missing'] === [])
                                <form method="POST" action="{{ route('dashboard.instructor.submit') }}" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full py-2 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-500 transition-all">
                                        Submit
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </x-account.card>
            @endif

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
            <x-account.recent-activity />

        </div>
    </div>

@endsection
