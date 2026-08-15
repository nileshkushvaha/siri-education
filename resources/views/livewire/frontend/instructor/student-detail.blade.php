<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-account.stat-card label="Total lessons" :value="(string) $summary->lessonsCount" gradient="from-indigo-500 to-violet-500"
            icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        <x-account.stat-card label="Completed lessons" :value="(string) $summary->completedLessons" gradient="from-emerald-500 to-teal-500"
            icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-account.stat-card label="Upcoming lesson" :value="viewer_date($summary->nextLessonAt) ?? 'None'" gradient="from-amber-500 to-orange-500"
            icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </div>

    <x-account.card>
        <div class="mb-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-300">Learning plan</p>
            <h2 class="mt-1 text-lg font-semibold text-white">Current status</h2>
        </div>
        @if($summary->learningPlanStatus)
            <x-ui.badge :color="$summary->learningPlanStatus->color()">{{ $summary->learningPlanStatus->label() }}</x-ui.badge>
        @else
            <p class="text-sm text-slate-400">No active learning plan.</p>
        @endif
    </x-account.card>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-indigo-300">Recent Lessons</h2>
        <x-account.card>
            @forelse ($recentLessons as $lesson)
                <div wire:key="recent-lesson-{{ $lesson['id'] }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ $lesson['subject'] }}</p>
                            <p class="text-xs text-slate-400">{{ viewer_datetime($lesson['starts_at']) }}</p>
                        </div>
                        <x-ui.badge :color="$lesson['status']->color()">{{ $lesson['status']->label() }}</x-ui.badge>
                    </div>
                </div>
            @empty
                <x-ui.empty-state title="No completed lessons yet." />
            @endforelse
        </x-account.card>
    </div>
</div>
