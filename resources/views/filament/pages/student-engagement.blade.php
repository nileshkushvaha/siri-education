<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $canView = $this->canViewReport();
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Country</label>
                    <select wire:model.live="countryId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->countryOptions() as $country)
                            <option value="{{ $country->id }}">{{ $country->name }}</option>
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Account status</label>
                    <select wire:model.live="studentStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->studentStatusOptions() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <button type="button" wire:click="resetFilters" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                    Reset filters
                </button>
            </div>
        </div>

        @if(! $canView)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view student engagement reporting.</p>
            </div>
        @else
            @php $summary = $this->summary(); @endphp

            {{-- ── Account & acquisition ───────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Total students' => $summary->totalStudents,
                    'New in period' => $summary->newInPeriod,
                    'Verified (total)' => $summary->verifiedTotal,
                    'Verified in period' => $summary->verifiedInPeriod,
                    'Suspended accounts' => $summary->byAccountStatus['suspended'] ?? 0,
                    'Archived accounts' => $summary->byAccountStatus['archived'] ?? 0,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            {{-- ── Engagement (period-scoped, explicitly not account status) ── --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Engaged in period' => $summary->engagedInPeriod,
                    'With bookings' => $summary->withBookingsInPeriod,
                    'With completed lessons' => $summary->withCompletedLessonsInPeriod,
                    'Active learning plans' => $summary->withActiveLearningPlans,
                    'Homework activity' => $summary->withHomeworkActivityInPeriod,
                    'Without recent activity' => $summary->withoutRecentLearningActivity,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                "Engaged in period" = students with at least one booking created or one lesson completed in the selected period — a reporting definition, not an account status.
                "Without recent activity" = non-suspended, non-archived student accounts with no qualifying learning activity in the period.
            </p>

            {{-- ── Participation & lifetime distribution ────────────────── --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Recurring participation (students, in period)</h3>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($summary->recurringParticipation as $bucket => $count)
                            <div class="flex items-center justify-between px-6 py-2 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', ucfirst($bucket)) }}</span>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Lifetime booking count (all students)</h3>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($summary->lifetimeBookingBuckets as $bucket => $count)
                            <div class="flex items-center justify-between px-6 py-2 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ $bucket }} bookings</span>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── Breakdowns ───────────────────────────────────────────── --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach($this->breakdowns() as $title => $rows)
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
                                <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No students match the selected filters.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ── Student engagement table ─────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Student engagement review</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Student</th>
                                <th class="px-4 py-2">Country</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Verified</th>
                                <th class="px-4 py-2">Lifetime bookings</th>
                                <th class="px-4 py-2">Bookings (period)</th>
                                <th class="px-4 py-2">Completed lessons (period)</th>
                                <th class="px-4 py-2">Active plans</th>
                                <th class="px-4 py-2">Last activity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($this->engagementRows() as $row)
                                <tr>
                                    <td class="px-6 py-2">
                                        @if($row->drillDownUrl)
                                            <a href="{{ $row->drillDownUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->studentLabel }}</a>
                                        @else
                                            {{ $row->studentLabel }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $row->countryLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->accountStatusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->verified ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-2">{{ $row->lifetimeBookingCount }}</td>
                                    <td class="px-4 py-2">{{ $row->bookingsInPeriod }}</td>
                                    <td class="px-4 py-2">{{ $row->completedLessonsInPeriod }}</td>
                                    <td class="px-4 py-2">{{ $row->activeLearningPlanCount }}</td>
                                    <td class="px-4 py-2">{{ $row->lastQualifyingActivityUtc?->timezone($freshness->reportingTimezone)->format('M j, Y') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-6 py-4 text-gray-500 dark:text-gray-400">No students match the selected filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $this->engagementRows()?->links() }}</div>
            </div>
        @endif

    </div>

</x-filament-panels::page>
