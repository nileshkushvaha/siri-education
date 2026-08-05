@php
    $started = $lesson->starts_at->isPast();
    $acceptsSubmissions = $started && $lesson->booking?->status?->value !== 'cancelled' && ! $lesson->status->isTerminal();
    $canConfirmOutcome = $lesson->status->isOpen() && $lesson->ends_at->isPast() && ! $lesson->hasFinalizedOutcome();
    $homeworkStatus = $this->homeworkStatusFor($lesson);
@endphp

<div class="mt-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-4">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Lesson details</p>
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Student</dt>
            <dd class="text-slate-300">{{ $lesson->student?->name ?? 'Student' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Subject</dt>
            <dd class="text-slate-300">{{ $lesson->subject?->name ?? 'General' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Date &amp; time</dt>
            <dd class="text-slate-300">{{ $lesson->starts_at->copy()->timezone($timezone)->format('M j, Y g:i A') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Duration</dt>
            <dd class="text-slate-300">{{ $lesson->starts_at->diffInMinutes($lesson->ends_at) }} minutes</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Meeting status</dt>
            <dd class="text-slate-300">{{ $lesson->booking?->meeting?->status?->label() ?? 'Not scheduled' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Lesson status</dt>
            <dd><x-ui.badge :color="$lesson->status->color()">{{ $lesson->status->label() }}</x-ui.badge></dd>
        </div>
        @if($homeworkStatus)
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Homework</dt>
                <dd class="text-slate-300">{{ $homeworkStatus }}</dd>
            </div>
        @endif
    </dl>

    @if($acceptsSubmissions || $canConfirmOutcome)
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-white/[0.05] pt-4">
            @if($acceptsSubmissions)
                <button type="button" wire:click="confirmAttendance('{{ $lesson->id }}')"
                        wire:loading.attr="disabled" wire:target="confirmAttendance('{{ $lesson->id }}')"
                        class="min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 transition disabled:opacity-50"
                        aria-label="Confirm your attendance for this lesson">
                    <span wire:loading.remove wire:target="confirmAttendance('{{ $lesson->id }}')">Confirm Attendance</span>
                    <span wire:loading wire:target="confirmAttendance('{{ $lesson->id }}')">Confirming…</span>
                </button>

                @if($reportingIssueId !== $lesson->id)
                    <button type="button" wire:click="startReportIssue('{{ $lesson->id }}')"
                            class="min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition"
                            aria-label="Report a technical issue for this lesson">
                        Report Issue
                    </button>
                @endif
            @endif

            @if($canConfirmOutcome)
                <button type="button" wire:click="confirmOutcome('{{ $lesson->id }}')"
                        wire:loading.attr="disabled" wire:target="confirmOutcome('{{ $lesson->id }}')"
                        class="min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50"
                        aria-label="Confirm the teaching outcome for this lesson">
                    <span wire:loading.remove wire:target="confirmOutcome('{{ $lesson->id }}')">Confirm Teaching Outcome</span>
                    <span wire:loading wire:target="confirmOutcome('{{ $lesson->id }}')">Confirming…</span>
                </button>
            @endif
        </div>
    @endif

    @if($reportingIssueId === $lesson->id)
        <form wire:submit="submitIssueReport('{{ $lesson->id }}')" class="mt-4 space-y-3 border-t border-white/[0.05] pt-4">
            <div>
                <label for="issue-category-{{ $lesson->id }}" class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Issue type</label>
                <select id="issue-category-{{ $lesson->id }}" wire:model="issue_category"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none">
                    <option value="">Select an issue…</option>
                    @foreach(\App\Lessons\Enums\TechnicalIssueCategory::cases() as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </select>
                @error('issue_category') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="issue-description-{{ $lesson->id }}" class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Description (optional)</label>
                <textarea id="issue-description-{{ $lesson->id }}" wire:model="issue_description" rows="3" maxlength="1000"
                          class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none"></textarea>
                @error('issue_description') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="submitIssueReport('{{ $lesson->id }}')"
                        class="min-h-11 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                    Submit Report
                </button>
                <button type="button" wire:click="cancelReportIssue"
                        class="min-h-11 px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if($lesson->outcome === $completedOutcome && $lesson->hasFinalizedOutcome())
        @php($feedback = $existingFeedback[$lesson->id] ?? null)
        <div class="mt-4 border-t border-white/[0.05] pt-4">
            @if($feedback)
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Private feedback &middot; visible only to you</p>
                <dl class="space-y-3 text-sm">
                    @if($feedback->attendanceStatusSnapshot)
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
                        @if($feedback->{$property})
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                                <dd class="text-slate-300">{{ $feedback->{$property} }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
                <p class="mt-4 text-xs text-slate-500">Submitted {{ $feedback->submittedAt->copy()->timezone($timezone)->format('M j, Y g:i A') }}. Feedback cannot be edited or deleted.</p>
            @else
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Private feedback &middot; visible only to you</p>
                <form wire:submit="submitFeedback('{{ $lesson->id }}')" class="space-y-4">
                    @if($lesson->student_attendance_status)
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
                            class="min-h-11 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                            Save private feedback
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</div>
