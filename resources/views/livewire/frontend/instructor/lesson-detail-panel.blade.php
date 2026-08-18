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

    {{-- AI lesson summary. Instructor-initiated, never automatic, and
         never recorded until the instructor approves their own text. --}}
    @if($lesson->outcome === $completedOutcome && $lesson->hasFinalizedOutcome())
        @php($aiSummary = $this->summaryFor($lesson))
        <div class="mt-4 border-t border-white/[0.05] pt-4">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Lesson summary</p>

            @if($aiSummary?->status === \App\Lessons\Summaries\Enums\LessonSummaryStatus::Approved)
                <p class="text-sm text-slate-200 whitespace-pre-line">{{ $aiSummary->approved_summary }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    Approved by you {{ viewer_datetime($aiSummary->approved_at) }}. Drafted with AI assistance.
                </p>

            @elseif($aiSummary?->status === \App\Lessons\Summaries\Enums\LessonSummaryStatus::Pending)
                <div wire:poll.5s class="flex items-center gap-2 text-xs text-slate-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Drafting summary&hellip;
                </div>

            @elseif($aiSummary?->status === \App\Lessons\Summaries\Enums\LessonSummaryStatus::Ready)
                @if($summaryEditingId === $aiSummary->id)
                    <p class="text-xs text-amber-300/80 mb-2">
                        Edit freely. What you approve is what is recorded — the draft is a starting point, not a record.
                    </p>
                    <form wire:submit="approveSummary" class="space-y-3">
                        <textarea wire:model="summaryText" rows="5"
                                  class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-emerald-500/40"></textarea>
                        @error('summaryText')
                            <p class="text-xs text-rose-400">{{ $message }}</p>
                        @enderror
                        <div class="flex items-center gap-2">
                            <button type="submit"
                                    class="min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 transition">
                                Approve summary
                            </button>
                            <button type="button" wire:click="cancelSummaryReview"
                                    class="min-h-11 px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition">
                                Cancel
                            </button>
                        </div>
                    </form>
                @else
                    <p class="text-xs text-slate-500 mb-2">
                        AI draft &middot; not recorded until you approve it
                        @if($aiSummary->confidencePercent() !== null)
                            &middot; <span title="The model's own stated certainty, based on how much detail this lesson recorded. Not a measure of the student.">AI confidence {{ $aiSummary->confidencePercent() }}%</span>
                        @endif
                    </p>
                    <p class="text-sm text-slate-300 whitespace-pre-line">{{ $aiSummary->lesson_summary }}</p>

                    @foreach ([
                        'Topics covered' => $aiSummary->topics_covered,
                        'Noted as going well' => $aiSummary->strengths_observed,
                        'Suggested practice' => $aiSummary->practice_recommendations,
                        'Suggested next focus' => $aiSummary->next_focus,
                    ] as $heading => $items)
                        @if(filled($items))
                            <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $heading }}</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach($items as $item)
                                    <li class="text-xs text-slate-300">&bull; {{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach

                    <p class="mt-3 text-[11px] text-amber-300/80">
                        Drafted from your notes only — check it describes the lesson accurately before approving.
                    </p>

                    <div class="flex items-center gap-2 mt-3">
                        <button type="button" wire:click="startSummaryReview('{{ $aiSummary->id }}')"
                                class="min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-emerald-200 bg-emerald-500/10 border border-emerald-400/20 hover:bg-emerald-500/20 transition">
                            Review &amp; approve
                        </button>
                        <button type="button" wire:click="discardSummary('{{ $aiSummary->id }}')"
                                class="min-h-11 px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition">
                            Discard
                        </button>
                    </div>
                @endif

            @else
                <p class="text-xs text-slate-400">
                    @if($aiSummary?->status === \App\Lessons\Summaries\Enums\LessonSummaryStatus::Failed)
                        No summary was produced. You can try again.
                    @else
                        Draft a summary of this lesson from your own completion notes.
                    @endif
                </p>
                <button type="button" wire:click="generateSummary('{{ $lesson->id }}')"
                        wire:confirm="This sends the lesson's subject, level, topic, your completion notes, the plan focus and any homework titles to the AI provider. Student and tutor names are removed first. Recordings are never used. Continue?"
                        wire:loading.attr="disabled"
                        class="mt-2 min-h-11 px-4 py-2 rounded-xl text-xs font-semibold text-emerald-200 bg-emerald-500/10 border border-emerald-400/20 hover:bg-emerald-500/20 transition">
                    Generate AI summary
                </button>
                @error('aiSummary')
                    <p class="mt-2 text-xs text-rose-400">{{ $message }}</p>
                @enderror
            @endif
        </div>
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
