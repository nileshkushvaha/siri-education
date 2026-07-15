<div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <x-account.card title="Profile Readiness">
            <div class="flex items-center justify-between gap-4">
                <span class="text-sm text-slate-400">Completion</span>
                <span class="text-sm font-semibold text-indigo-300">{{ $profileCompletion }}%</span>
            </div>
            <div class="h-2 rounded-full bg-white/[0.06] overflow-hidden mt-3">
                <div class="h-full rounded-full bg-indigo-500" style="width: {{ $profileCompletion }}%"></div>
            </div>
            @if($profileMissingItems)
                <div class="flex flex-wrap gap-2 mt-4">
                    @foreach(array_slice($profileMissingItems, 0, 3) as $item)
                        <span class="px-2 py-1 rounded-lg bg-white/[0.04] text-xs text-slate-300">{{ $item }}</span>
                    @endforeach
                </div>
            @endif
            <a href="{{ route('profile.show') }}" class="inline-block mt-4 text-xs font-semibold text-indigo-300 hover:text-indigo-200">
                Complete profile →
            </a>
        </x-account.card>

        <x-account.card title="Learning Goals">
            <p class="text-3xl font-bold text-white">{{ $activeGoals->count() }}</p>
            <p class="text-sm text-slate-400 mt-1">Active goals</p>
            @if($activeGoals->isNotEmpty())
                <p class="text-xs text-slate-500 mt-3 truncate">{{ $activeGoals->first()->title }}</p>
            @endif
            <a href="{{ route('dashboard.learning-goals') }}" class="inline-block mt-4 text-xs font-semibold text-indigo-300 hover:text-indigo-200">
                Manage goals →
            </a>
        </x-account.card>

        <x-account.card title="Favorite Instructors">
            <p class="text-3xl font-bold text-white">{{ $favoriteInstructorCount }}</p>
            <p class="text-sm text-slate-400 mt-1">{{ $bookableFavoriteInstructorCount }} currently bookable</p>
            <a href="{{ route('dashboard.wishlist') }}" class="inline-block mt-4 text-xs font-semibold text-indigo-300 hover:text-indigo-200">
                View favorites →
            </a>
        </x-account.card>
    </div>

    <x-account.card title="Learning Plan" link-text="View plans →" :link-href="route('dashboard.learning-plans')">
        @if($currentLearningPlan)
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-white">{{ $currentLearningPlan->title }}</p>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $currentLearningPlan->subject?->name }} · {{ $currentLearningPlan->status->label() }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Instructor: {{ $currentLearningPlan->primaryInstructor?->name ?? 'Not assigned yet' }}
                    </p>
                </div>
                <div class="md:text-right">
                    <p class="text-2xl font-bold text-white">{{ $currentLearningPlan->progress_percent ?? 0 }}%</p>
                    <p class="text-xs text-slate-500">{{ $activeLearningPlanCount }} active plan{{ $activeLearningPlanCount === 1 ? '' : 's' }}</p>
                </div>
            </div>
        @else
            <div class="py-6 text-center">
                <p class="text-sm font-semibold text-slate-300">No learning plan yet.</p>
                <p class="text-sm text-slate-500 mt-1">Create a plan from a learning goal when you are ready for instructor guidance.</p>
            </div>
        @endif
    </x-account.card>

    @if($preferredSubjects->isNotEmpty())
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach($preferredSubjects->take(8) as $subject)
                <span class="px-3 py-1.5 rounded-full bg-white/[0.05] border border-white/[0.08] text-xs text-slate-300">{{ $subject->name }}</span>
            @endforeach
        </div>
    @endif

    {{-- ── Stats Grid ───────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
        <x-account.stat-card
            label="Upcoming Classes"
            :value="(string) $upcomingCount"
            gradient="from-indigo-500 to-violet-500"
            icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
        <x-account.stat-card
            label="Completed Sessions"
            :value="(string) $completedSessions"
            gradient="from-emerald-500 to-teal-500"
            icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
        />
        <x-account.stat-card
            label="Hours Learned"
            :value="number_format($totalHours, 1)"
            gradient="from-amber-500 to-orange-500"
            icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
        <x-account.stat-card
            label="Homework Pending"
            :value="(string) $pendingHomeworkCount"
            gradient="from-pink-500 to-rose-500"
            icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
        />
    </div>

    <x-account.card title="Next Classes" link-text="View all →" :link-href="route('dashboard.upcoming-classes')">
        @forelse($nextClasses as $booking)
            <div wire:key="next-class-{{ $booking->id }}" class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">with {{ $booking->instructor?->name ?? 'Teacher' }}</p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-xs font-medium text-indigo-300">{{ $booking->starts_at->format('M j, g:i A') }}</p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <div class="w-20 h-20 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mb-4">
                    <svg class="w-10 h-10 text-indigo-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-slate-300 font-semibold mb-2">No upcoming classes</h3>
                <p class="text-slate-400 text-sm mb-5 max-w-xs">Book a session with a teacher to get started.</p>
                <a href="{{ route('booking.create') }}" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all shadow-lg shadow-indigo-500/20">
                    Book a Class
                </a>
            </div>
        @endforelse
    </x-account.card>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
        @if($wallet)
            <a href="{{ route('dashboard.wallet') }}" class="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4 hover:bg-white/[0.05] transition-colors">
                <p class="text-sm font-semibold text-white">Wallet</p>
                <p class="text-lg font-bold text-emerald-400 mt-1">{{ $wallet['available_balance'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Available balance &middot; {{ $wallet['balance'] }} total</p>
            </a>
        @endif

        @foreach($safePlaceholders as $label => $message)
            <div class="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
                <p class="text-sm font-semibold text-white capitalize">{{ $label }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ $message }}</p>
            </div>
        @endforeach
    </div>

    @if($overdueHomeworkCount > 0)
        <div class="rounded-2xl border border-rose-500/20 p-5 relative overflow-hidden mt-6"
             style="background:linear-gradient(135deg,rgba(244,63,94,.06),rgba(225,29,72,.04))">
            <div class="relative z-10">
                <p class="text-rose-300 font-bold text-lg">{{ $overdueHomeworkCount }} homework overdue</p>
                <p class="text-slate-400 text-xs mt-1">Catch up before your next class.</p>
                <a href="{{ route('dashboard.homework') }}" class="inline-block mt-3 text-xs font-semibold text-rose-300 hover:text-rose-200">View homework →</a>
            </div>
        </div>
    @endif

    <div class="mt-6">
        <x-account.quick-actions />
    </div>
</div>
