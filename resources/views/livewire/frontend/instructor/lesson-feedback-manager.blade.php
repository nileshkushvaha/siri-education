<div class="space-y-6">
    @if (session('lessons-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ session('lessons-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    <x-account.card>
        @forelse ($lessons as $lesson)
            @php($feedback = $existingFeedback[$lesson->id] ?? null)
            <div wire:key="lesson-{{ $lesson->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $lesson->student?->first_name ?? 'Student' }}</p>
                            <x-ui.badge :color="$lesson->status->color()">{{ $lesson->status->label() }}</x-ui.badge>
                            @if ($lesson->outcome !== null && $lesson->hasFinalizedOutcome())
                                <x-ui.badge :color="$lesson->outcome->color()">{{ $lesson->outcome->label() }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400">
                            {{ $lesson->subject?->name ?? 'General' }} &middot; {{ $lesson->starts_at->format('M j, Y g:i A') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($lesson->outcome === $completedOutcome && $lesson->hasFinalizedOutcome())
                            @if ($feedback)
                                <span class="px-3 py-1.5 rounded-lg text-xs font-semibold text-emerald-300 border border-emerald-400/30 bg-emerald-500/10">
                                    Private feedback submitted
                                </span>
                                <button type="button" wire:click="toggleExpand('{{ $lesson->id }}')"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                    {{ $expandedLessonId === $lesson->id ? 'Hide' : 'View' }}
                                </button>
                            @else
                                <button type="button" wire:click="toggleExpand('{{ $lesson->id }}')"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                                    {{ $expandedLessonId === $lesson->id ? 'Cancel' : 'Give feedback' }}
                                </button>
                            @endif
                        @endif
                    </div>
                </div>

                @if ($expandedLessonId === $lesson->id)
                    <div class="mt-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-4">
                        @if ($feedback)
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Private feedback &middot; visible only to you</p>
                            <dl class="space-y-3 text-sm">
                                @if ($feedback->attendanceStatusSnapshot)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Finalized attendance</dt>
                                        <dd class="text-slate-300">{{ $feedback->attendanceStatusSnapshot->label() }}</dd>
                                    </div>
                                @endif
                                @foreach ([
                                    'attendanceObservation' => 'Attendance observation',
                                    'preparednessObservation' => 'Preparedness',
                                    'homeworkCompletionObservation' => 'Homework completion',
                                    'engagementObservation' => 'Engagement',
                                    'learningAttitudeObservation' => 'Learning attitude',
                                    'areasNeedingSupport' => 'Areas needing support',
                                    'privateNotes' => 'Private notes',
                                ] as $property => $label)
                                    @if ($feedback->{$property})
                                        <div>
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                                            <dd class="text-slate-300">{{ $feedback->{$property} }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                            <p class="mt-4 text-xs text-slate-500">Submitted {{ $feedback->submittedAt->format('M j, Y g:i A') }}. Feedback cannot be edited or deleted.</p>
                        @else
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Private feedback &middot; visible only to you</p>
                            <form wire:submit="submitFeedback('{{ $lesson->id }}')" class="space-y-4">
                                @if ($lesson->student_attendance_status)
                                    <p class="text-xs text-slate-500">Finalized attendance: <span class="text-slate-300">{{ $lesson->student_attendance_status->label() }}</span> (not editable here)</p>
                                @endif
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Attendance observation</label>
                                    <textarea wire:model="attendance_observation" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('attendance_observation') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Preparedness</label>
                                    <textarea wire:model="preparedness_observation" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('preparedness_observation') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Homework completion</label>
                                    <textarea wire:model="homework_completion_observation" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('homework_completion_observation') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Engagement</label>
                                    <textarea wire:model="engagement_observation" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('engagement_observation') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Learning attitude</label>
                                    <textarea wire:model="learning_attitude_observation" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('learning_attitude_observation') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Areas needing support</label>
                                    <textarea wire:model="areas_needing_support" rows="2" maxlength="1000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('areas_needing_support') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Private notes for learning continuity</label>
                                    <textarea wire:model="private_notes" rows="3" maxlength="2000"
                                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                                    @error('private_notes') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitFeedback('{{ $lesson->id }}')"
                                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                                        Save private feedback
                                    </button>
                                    <button type="button" wire:click="toggleExpand('{{ $lesson->id }}')"
                                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No lessons yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Your lessons will appear here once they are scheduled.</p>
            </div>
        @endforelse

        @if ($lessons->hasPages())
            <div class="pt-4">
                {{ $lessons->links() }}
            </div>
        @endif
    </x-account.card>
</div>
