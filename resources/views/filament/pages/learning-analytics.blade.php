<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $plans = $this->planSummary();
        $goals = $this->goalSummary();
        $homework = $this->homeworkSummary();
        $milestones = $this->milestoneReviewSummary();
        $trends = $this->trends();
    @endphp

    <div class="space-y-6">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
                Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
                Period: {{ $freshness->periodLabel }}
            </p>
        </div>

        {{-- ── Filters ─────────────────────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Period</label>
                    <select wire:model.live="periodPreset" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        @foreach($this->periodPresets() as $preset)
                            <option value="{{ $preset->value }}">{{ $preset->label() }}</option>
                        @endforeach
                    </select>
                </div>

                @if($periodPreset === 'custom')
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Start date</label>
                        <input type="date" wire:model.live="customStart" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">End date</label>
                        <input type="date" wire:model.live="customEnd" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10" />
                    </div>
                @endif

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Subject</label>
                    <select wire:model.live="subjectId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->subjectOptions() as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Academic level</label>
                    <select wire:model.live="educationLevelId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->academicLevelOptions() as $level)
                            <option value="{{ $level->id }}">{{ $level->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Plan status</label>
                    <select wire:model.live="planStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->planStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ ucwords(str_replace('_', ' ', $status->value)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Goal status</label>
                    <select wire:model.live="goalStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->goalStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Homework status</label>
                    <select wire:model.live="homeworkStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->homeworkStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end">
                    <button wire:click="resetFilters" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5">
                        Reset filters
                    </button>
                </div>
            </div>
        </div>

        {{-- ── Learning Plans ──────────────────────────────────────────── --}}
        @if($plans !== null)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Plans (all, current)' => $plans->totalPlans,
                    'Created in period' => $plans->createdInPeriod,
                    'Activated in period' => $plans->activatedInPeriod,
                    'Completed in period' => $plans->completedInPeriod,
                    'Archived in period' => $plans->archivedInPeriod,
                    'Avg progress (active plans)' => $plans->averageActiveProgressPercent !== null ? $plans->averageActiveProgressPercent.'%' : 'N/A (no active plans)',
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current plans by lifecycle status</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($plans->currentByStatus as $status => $count)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $status)) }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No Learning Plans match the selected filters.</p>
                        @endforelse
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Attention: {{ $plans->activePlansPastTargetDate }} active past target date · {{ $plans->activePlansWithoutInstructor }} active without instructor
                    </p>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Plans by subject (current)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($plans->bySubject as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No Learning Plans match the selected filters.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Plans by instructor (current)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($plans->byInstructor as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No Learning Plans match the selected filters.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Learning Goals ──────────────────────────────────────────── --}}
        @if($goals !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Learning goals</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Created in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->createdInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Completed in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->completedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Archived in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->archivedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Linked to a plan: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->goalsLinkedToPlans }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Not yet linked: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->goalsWithoutPlans }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Students with multiple active goals: <span class="font-semibold text-gray-950 dark:text-white">{{ $goals->studentsWithMultipleActiveGoals }}</span></span>
                </div>
                <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                    @foreach($goals->currentByStatus as $status => $count)
                        <span>{{ ucfirst($status) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">A goal is a student-defined objective; a goal without a plan is a normal early state, not an error.</p>
            </div>
        @endif

        {{-- ── Homework ────────────────────────────────────────────────── --}}
        @if($homework !== null)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Assigned in period' => $homework->assignedInPeriod,
                    'Submitted in period' => $homework->submittedInPeriod,
                    'Submitted late in period' => $homework->submittedLateInPeriod,
                    'Currently overdue (now)' => $homework->currentlyOverdue,
                    'Submission rate' => $homework->submissionRate !== null ? $homework->submissionRate.'%' : 'N/A (no elapsed due dates)',
                    'On-time submission rate' => $homework->onTimeSubmissionRate !== null ? $homework->onTimeSubmissionRate.'%' : 'N/A (no elapsed due dates)',
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Rates cover assignments whose due date fell inside the period and has already elapsed.
                Submission and grading are distinct states; grading has no stored timestamp, so graded counts
                ({{ $homework->gradedCurrent }} currently graded) are as-of-now and no average review time is reported.
            </p>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Assignment frequency by instructor (period)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($homework->byTeacher as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No homework was assigned in this period.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">By subject (assignment-declared text, period)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($homework->bySubjectText as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No homework was assigned in this period.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Milestones & progress reviews ───────────────────────────── --}}
        @if($milestones !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Milestones &amp; progress reviews</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Milestones achieved in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->milestonesAchievedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Plans with milestones: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->plansWithMilestones }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Active plans without milestones: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->activePlansWithoutMilestones }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Reviews completed in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->reviewsCompletedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Plans reviewed in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->plansReviewedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Plans currently review-due: <span class="font-semibold text-gray-950 dark:text-white">{{ $milestones->plansCurrentlyReviewDue }}</span></span>
                </div>
                <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                    @foreach($milestones->currentMilestonesByStatus as $status => $count)
                        <span>{{ ucwords(str_replace('_', ' ', $status)) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Every milestone here is plan-assigned; an achievement is strictly a Completed status with its own timestamp.
                    Reviews have no due date of their own — "review due" is the plan lifecycle status.
                </p>
            </div>
        @endif

        {{-- ── Trends ──────────────────────────────────────────────────── --}}
        @if($trends !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Daily activity (reporting timezone; current partial day included)</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-2 py-1">Series</th>
                                <th class="px-2 py-1">Total</th>
                                <th class="px-2 py-1">Daily</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach([
                                'Plans created' => $trends->plansCreated,
                                'Plans activated' => $trends->plansActivated,
                                'Plans completed' => $trends->plansCompleted,
                                'Homework assigned' => $trends->homeworkAssigned,
                                'Homework submitted' => $trends->homeworkSubmitted,
                                'Milestones achieved' => $trends->milestonesAchieved,
                                'Reviews completed' => $trends->reviewsCompleted,
                            ] as $label => $series)
                                <tr>
                                    <td class="px-2 py-1 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $label }}</td>
                                    <td class="px-2 py-1 font-semibold text-gray-950 dark:text-white">{{ array_sum($series) }}</td>
                                    <td class="px-2 py-1 text-gray-500 dark:text-gray-400 font-mono">{{ implode(' ', $series) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── Learning Plan review table ──────────────────────────────── --}}
        @if(($planRows = $this->planReviewRows()) !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Learning Plan review (open plans, attention first)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Read-only. Attention flags are source-backed states, never a score.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Student</th>
                                <th class="px-4 py-2">Instructor</th>
                                <th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Progress</th>
                                <th class="px-4 py-2">Milestones</th>
                                <th class="px-4 py-2">Started</th>
                                <th class="px-4 py-2">Target</th>
                                <th class="px-4 py-2">Last review</th>
                                <th class="px-4 py-2">Attention</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($planRows as $row)
                                <tr>
                                    <td class="px-6 py-2">
                                        @if($row->drillDownUrl)
                                            <a href="{{ $row->drillDownUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->studentLabel }}</a>
                                        @else
                                            {{ $row->studentLabel }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $row->instructorLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->subjectLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->statusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->progressPercent }}%</td>
                                    <td class="px-4 py-2">{{ $row->milestonesAchieved }}/{{ $row->milestonesTotal }}</td>
                                    <td class="px-4 py-2">{{ $row->startedAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y') ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->targetDate ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->lastReviewAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y') ?? 'Never' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        @if($row->reviewDue)<span class="text-warning-600">Review due</span>@endif
                                        @if($row->targetDatePassed)<span class="text-danger-600">Target passed</span>@endif
                                        @if($row->missingInstructor)<span class="text-danger-600">No instructor</span>@endif
                                        @if(!$row->reviewDue && !$row->targetDatePassed && !$row->missingInstructor)—@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-6 py-4 text-gray-500 dark:text-gray-400">No Learning Plans match the selected filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $planRows->links() }}</div>
            </div>
        @endif

        {{-- ── Homework attention table ────────────────────────────────── --}}
        @if(($homeworkRows = $this->homeworkAttentionRows()) !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Homework needing attention (overdue or awaiting grading)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Read-only. Submission content, grades and feedback are never shown here.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Student</th>
                                <th class="px-4 py-2">Instructor</th>
                                <th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Assigned</th>
                                <th class="px-4 py-2">Due</th>
                                <th class="px-4 py-2">Submitted</th>
                                <th class="px-4 py-2">Days past due</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($homeworkRows as $row)
                                <tr>
                                    <td class="px-6 py-2">{{ $row->studentLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->teacherLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->subjectText }}</td>
                                    <td class="px-4 py-2">{{ $row->statusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->assignedAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y') }}</td>
                                    <td class="px-4 py-2">{{ $row->dueAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">{{ $row->submittedAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y H:i') ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->ageDays }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">No homework is currently overdue or awaiting grading.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $homeworkRows->links() }}</div>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Curriculum progress is unavailable because no authoritative curriculum-version progress mapping exists in this platform version.
            Resource-usage analytics are unavailable because no student access events are recorded.
            No learning-consistency or academic score is computed — descriptive, source-backed counts only.
        </p>

    </div>

</x-filament-panels::page>
