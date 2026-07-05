<div>
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
            label="Teaching Hours"
            :value="number_format($teachingHours, 1)"
            gradient="from-amber-500 to-orange-500"
            icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
        <x-account.stat-card
            label="Subjects"
            :value="(string) $subjectCount"
            gradient="from-pink-500 to-rose-500"
            icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
        />
    </div>

    <x-account.card title="Next Classes">
        @forelse($nextClasses as $booking)
            <div wire:key="instructor-next-class-{{ $booking->id }}" class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">with {{ $booking->attendee?->name ?? $booking->guest_name ?? 'Student' }}</p>
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
                <p class="text-slate-400 text-sm max-w-xs">New classes will appear here when students book with you.</p>
            </div>
        @endforelse
    </x-account.card>

    <div class="mt-6">
        <x-account.card title="Instructor Progress">
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-white">{{ $onboarding['status']?->label() ?? 'Not started' }}</p>
                        <p class="text-xs text-slate-400 mt-1">Keep your teaching profile ready for review.</p>
                    </div>
                    <span class="text-sm font-semibold text-indigo-300">{{ $onboarding['percentage'] }}%</span>
                </div>
                <div class="h-2 rounded-full bg-white/[0.06] overflow-hidden">
                    <div class="h-full rounded-full bg-indigo-500" style="width: {{ $onboarding['percentage'] }}%"></div>
                </div>
                @if($onboarding['missing'])
                    <div class="flex flex-wrap gap-2">
                        @foreach(array_slice($onboarding['missing'], 0, 4) as $missing)
                            <span class="px-2 py-1 rounded-lg bg-white/[0.04] text-xs text-slate-300">{{ $missing }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="flex gap-2">
                    <a href="{{ route('dashboard.instructor.onboarding') }}" class="flex-1 text-center py-2 rounded-xl border border-white/[0.10] text-slate-300 hover:text-white hover:bg-white/[0.05] text-sm font-medium transition-all">
                        {{ $onboarding['status'] ? 'Continue' : 'Start' }}
                    </a>

                    @if($onboarding['next_action'] === 'submit_application')
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
    </div>
</div>
