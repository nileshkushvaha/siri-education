<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $canBookingLesson = $this->canViewBookingLessonSection();
        $canMeeting = $this->canViewMeetingSection();
    @endphp

    <div class="space-y-6">

        {{-- ── Freshness / timezone banner ─────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
                Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
                Period: {{ $freshness->periodLabel }}
            </p>
        </div>

        <div class="flex justify-end">
            @include('filament.pages.partials.report-export-button', ['exportKey' => 'operations_lesson_rows', 'label' => 'Lesson rows'])
        </div>


        {{-- ── Filters ──────────────────────────────────────────────────── --}}
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Country</label>
                    <select wire:model.live="countryId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->countryOptions() as $country)
                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                        @endforeach
                    </select>
                </div>

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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Instructor ID</label>
                    <input type="number" wire:model.live="instructorId" placeholder="Any" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10" />
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Booking type</label>
                    <select wire:model.live="bookingType" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->bookingTypeOptions() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Booking status</label>
                    <select wire:model.live="bookingStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->bookingStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Lesson status</label>
                    <select wire:model.live="lessonStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->lessonStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Lesson outcome</label>
                    <select wire:model.live="lessonOutcome" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->lessonOutcomeOptions() as $outcome)
                            <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                        @endforeach
                    </select>
                </div>

                @if($canMeeting)
                    <div>
                        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Meeting status</label>
                        <select wire:model.live="meetingStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                            <option value="">All</option>
                            @foreach($this->meetingStatusOptions() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="mt-4">
                <button type="button" wire:click="resetFilters" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                    Reset filters
                </button>
            </div>
        </div>

        @if(! $canBookingLesson)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view booking and lesson operations data.</p>
            </div>
        @else
            @php $booking = $this->bookingSummary(); $lesson = $this->lessonSummary(); @endphp

            {{-- ── Booking summary ─────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Total bookings' => $booking->total,
                    'Free demo' => $booking->byType['free_demo'] ?? 0,
                    'Paid 1:1' => $booking->byType['paid_one_to_one'] ?? 0,
                    'Cancelled' => $booking->byStatus['cancelled'] ?? 0,
                    'Rescheduled' => $booking->rescheduled,
                    'Single / Daily / Weekly' => ($booking->byRecurrence['single'] ?? 0).' / '.($booking->byRecurrence['daily'] ?? 0).' / '.($booking->byRecurrence['weekly'] ?? 0),
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            @if(($booking->byRecurrence['unknown_historical'] ?? 0) > 0)
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $booking->byRecurrence['unknown_historical'] }} booking(s) in this period were part of a recurring series created before frequency tracking existed — shown as "Unknown (historical)", never counted as single.
                </p>
            @endif

            {{-- ── Lesson outcome summary ──────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Lessons scheduled' => $lesson->scheduled,
                    'Finalized' => $lesson->finalized,
                    'Completed' => $lesson->byOutcome['completed'] ?? 0,
                    'Student no-show' => $lesson->byOutcome['student_no_show'] ?? 0,
                    'Instructor no-show' => $lesson->byOutcome['instructor_no_show'] ?? 0,
                    'Technical issue' => $lesson->byOutcome['technical_issue'] ?? 0,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Currently disputed</p>
                    <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $lesson->disputed }}</p>
                </div>
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Unfinalized, past due</p>
                    <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $lesson->unfinalizedPastDue }}</p>
                </div>
            </div>

            {{-- ── Breakdown tables ─────────────────────────────────────────── --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach([
                    'Top subjects' => $this->bySubject(),
                    'Top instructors' => $this->byInstructor(),
                    'Top countries' => $this->byCountry(),
                    'By duration' => $this->byDuration(),
                ] as $title => $rows)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $title }}</h3>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($rows as $row)
                                <div class="flex items-center justify-between px-6 py-2 text-sm">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span>
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span>
                                </div>
                            @empty
                                <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No data for the selected filters.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ── Lessons in period (actionable) ──────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Lessons in the selected period</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Scheduled</th>
                                <th class="px-4 py-2">Reference</th>
                                <th class="px-4 py-2">Type</th>
                                <th class="px-4 py-2">Student</th>
                                <th class="px-4 py-2">Instructor</th>
                                <th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Booking</th>
                                <th class="px-4 py-2">Lesson</th>
                                <th class="px-4 py-2">Meeting</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($this->lessonsInPeriod() as $row)
                                <tr>
                                    <td class="px-6 py-2 text-gray-700 dark:text-gray-300">{{ $row->scheduledAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">
                                        @if($row->bookingViewUrl)
                                            <a href="{{ $row->bookingViewUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->bookingReference }}</a>
                                        @else
                                            {{ $row->bookingReference }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $row->bookingTypeLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->studentLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->instructorLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->subjectLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->bookingStatusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->lessonOutcomeLabel ?? $row->lessonStatusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->meetingStatusLabel ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-6 py-4 text-gray-500 dark:text-gray-400">No lessons match the selected filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $this->lessonsInPeriod()?->links() }}</div>
            </div>

            {{-- ── No-shows & technical issues (actionable) ────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">No-shows &amp; technical issues</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Scheduled</th>
                                <th class="px-4 py-2">Student</th>
                                <th class="px-4 py-2">Instructor</th>
                                <th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Outcome</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Booking</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($this->noShowAndTechnicalIssues() as $row)
                                <tr>
                                    <td class="px-6 py-2 text-gray-700 dark:text-gray-300">{{ $row->scheduledAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">{{ $row->studentLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->instructorLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->subjectLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->outcomeLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->lessonStatusLabel }}</td>
                                    <td class="px-4 py-2">
                                        @if($row->bookingViewUrl)
                                            <a href="{{ $row->bookingViewUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">View</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">No no-shows or technical issues in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $this->noShowAndTechnicalIssues()?->links() }}</div>
            </div>
        @endif

        {{-- ── Meeting section (separately permissioned) ───────────────────── --}}
        @if($canBookingLesson && $canMeeting)
            @php $meeting = $this->meetingSummary(); @endphp

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Meetings created' => $meeting->created,
                    'Creation failed' => $meeting->failed,
                    'Missing meeting' => $meeting->missingMeeting,
                    'Student joined' => $meeting->studentJoined,
                    'Instructor joined' => $meeting->instructorJoined,
                    'Both joined' => $meeting->bothJoined,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Meeting issues</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Scheduled</th>
                                <th class="px-4 py-2">Reference</th>
                                <th class="px-4 py-2">Instructor</th>
                                <th class="px-4 py-2">Student</th>
                                <th class="px-4 py-2">Issue</th>
                                <th class="px-4 py-2">Meeting status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($this->meetingIssues() as $row)
                                <tr>
                                    <td class="px-6 py-2 text-gray-700 dark:text-gray-300">{{ $row->scheduledAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                                    <td class="px-4 py-2">
                                        @if($row->bookingViewUrl)
                                            <a href="{{ $row->bookingViewUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->bookingReference }}</a>
                                        @else
                                            {{ $row->bookingReference }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $row->instructorLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->studentLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->issueLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->meetingStatusLabel ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-4 text-gray-500 dark:text-gray-400">No meeting issues in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $this->meetingIssues()?->links() }}</div>
            </div>
        @elseif($canBookingLesson && ! $canMeeting)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view meeting operations data.</p>
            </div>
        @endif

    </div>

</x-filament-panels::page>
