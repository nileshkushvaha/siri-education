<div>
    <x-account.card>
        @forelse ($students as $student)
            <div wire:key="student-{{ $student->studentId }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-sm font-bold text-white">
                            @if($student->avatarUrl)
                                <img src="{{ $student->avatarUrl }}" alt="{{ $student->name }}" class="h-full w-full object-cover">
                            @else
                                {{ mb_substr($student->name, 0, 1) }}
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ $student->name }}</p>
                            <p class="text-xs text-slate-400">
                                {{ $student->lessonsCount }} {{ Str::plural('lesson', $student->lessonsCount) }}
                                &middot; {{ $student->completedLessons }} completed
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 sm:gap-6">
                        <div class="text-left sm:text-right">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Last lesson</p>
                            <p class="text-xs text-slate-300">{{ $student->lastLessonAt?->format('M j, Y') ?? '—' }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Upcoming</p>
                            <p class="text-xs text-slate-300">{{ $student->nextLessonAt?->format('M j, Y') ?? 'None' }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Learning plan</p>
                            @if($student->learningPlanStatus)
                                <x-ui.badge :color="$student->learningPlanStatus->color()">{{ $student->learningPlanStatus->label() }}</x-ui.badge>
                            @else
                                <p class="text-xs text-slate-500">No active learning plan.</p>
                            @endif
                        </div>
                        <a href="{{ route('dashboard.instructor.students.show', $student->studentSlug) }}"
                           class="min-h-11 inline-flex items-center rounded-xl border border-white/[0.10] px-4 text-xs font-semibold text-slate-300 hover:bg-white/[0.05] transition">
                            View
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.empty-state title="You don't have any students yet." description="Students will appear here after your first lesson." />
        @endforelse

        @if ($students->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $students->links() }}
            </div>
        @endif
    </x-account.card>
</div>
