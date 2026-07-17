<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $canView = $this->canViewReport();
        $canQuality = $this->canViewQuality();
    @endphp

    <div class="space-y-6">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
                Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
                Period: {{ $freshness->periodLabel }}
            </p>
        </div>

        <div class="flex justify-end">
            @include('filament.pages.partials.report-export-button', ['exportKey' => 'instructor_performance_rows', 'label' => 'Performance rows'])
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Subject (taught)</label>
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Lifecycle status</label>
                    <select wire:model.live="instructorStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->instructorStatusOptions() as $status)
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
                <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view instructor performance reporting.</p>
            </div>
        @else
            @php
                $lifecycle = $this->lifecycle();
                $activity = $this->activity();
                $conversion = $this->conversion();
            @endphp

            {{-- ── Lifecycle (current status) ───────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Lifecycle (current status) — {{ $lifecycle->total }} instructors</h3>
                </div>
                <div class="grid grid-cols-2 gap-0 sm:grid-cols-4 lg:grid-cols-6 divide-x divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($lifecycle->byStatus as $status => $count)
                        <div class="px-4 py-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', ucfirst($status)) }}</p>
                            <p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $count }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-white/5 text-xs text-gray-500 dark:text-gray-400">
                    New accounts in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $lifecycle->newAccountsInPeriod }}</span> ·
                    Applications submitted: <span class="font-semibold text-gray-950 dark:text-white">{{ $lifecycle->applicationsSubmittedInPeriod }}</span> ·
                    Approvals: <span class="font-semibold text-gray-950 dark:text-white">{{ $lifecycle->approvalsInPeriod }}</span>
                </div>
            </div>

            {{-- ── Teaching activity ────────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Demo bookings' => $activity->demoBookings,
                    'Paid bookings' => $activity->paidBookings,
                    'Completed lessons' => $activity->completedLessons,
                    'Student no-shows' => $activity->studentNoShows,
                    'Instructor no-shows' => $activity->instructorNoShows,
                    'Technical issues' => $activity->technicalIssues,
                    'Cancelled bookings' => $activity->cancelledBookings,
                    'Unique students' => $activity->uniqueStudents,
                    'Unique paid students' => $activity->uniquePaidStudents,
                    'Repeat paid students' => $activity->repeatPaidStudents,
                    'Booked teaching hours' => $activity->bookedTeachingHours,
                    'Published weekly availability (current)' => $activity->publishedWeeklyAvailabilityHours,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                "Repeat paid students" = students with two or more lifetime non-cancelled paid bookings with the same instructor — an activity proxy, not a retention rate.
                "Published weekly availability" reflects the CURRENT weekly schedule only; historical availability is not preserved, so no utilization rate is reported.
            </p>

            {{-- ── Demo-to-paid conversion ──────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Demo-to-paid conversion</h3>
                @if($conversion->conversionRate !== null)
                    <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">{{ $conversion->conversionRate }}%</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $conversion->convertedBookers }} of {{ $conversion->demoBookers }} demo students later created a paid booking (any instructor, any subject — the platform's existing conversion definition).</p>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Conversion cannot be calculated for this period — no demo bookings.</p>
                @endif
            </div>

            {{-- ── Quality (separately permissioned) ─────────────────────── --}}
            @if($canQuality)
                @php $quality = $this->quality(); @endphp
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Platform average rating</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $quality->platformAverageRating !== null ? number_format($quality->platformAverageRating, 2) : '—' }}</p>
                    </div>
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Instructors with published ratings</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $quality->instructorsWithPublishedRatings }}</p>
                    </div>
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Active quality alerts</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $quality->activeQualityAlerts }}</p>
                    </div>
                </div>
            @else
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view quality metrics.</p>
                </div>
            @endif

            {{-- ── Instructor performance table ─────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Instructor performance</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-6 py-2">Instructor</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Country</th>
                                <th class="px-4 py-2">Demo</th>
                                <th class="px-4 py-2">Paid</th>
                                <th class="px-4 py-2">Completed</th>
                                <th class="px-4 py-2">Students</th>
                                <th class="px-4 py-2">No-shows</th>
                                <th class="px-4 py-2">Booked hrs</th>
                                <th class="px-4 py-2">Rating</th>
                                <th class="px-4 py-2">Reviews</th>
                                @if($canQuality)
                                    <th class="px-4 py-2">Alerts</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($this->performanceRows() as $row)
                                <tr>
                                    <td class="px-6 py-2">
                                        @if($row->drillDownUrl)
                                            <a href="{{ $row->drillDownUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->instructorLabel }}</a>
                                        @else
                                            {{ $row->instructorLabel }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $row->statusLabel }}</td>
                                    <td class="px-4 py-2">{{ $row->countryLabel ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->demoBookings }}</td>
                                    <td class="px-4 py-2">{{ $row->paidBookings }}</td>
                                    <td class="px-4 py-2">{{ $row->completedLessons }}</td>
                                    <td class="px-4 py-2">{{ $row->uniqueStudents }}</td>
                                    <td class="px-4 py-2">{{ $row->instructorNoShows }}</td>
                                    <td class="px-4 py-2">{{ $row->bookedHours }}</td>
                                    <td class="px-4 py-2">{{ $row->averageRating !== null ? number_format($row->averageRating, 2) : '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->reviewCount }}</td>
                                    @if($canQuality)
                                        <td class="px-4 py-2">{{ $row->activeQualityAlerts ?? '—' }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="12" class="px-6 py-4 text-gray-500 dark:text-gray-400">No instructors match the selected filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3">{{ $this->performanceRows()?->links() }}</div>
            </div>
        @endif

    </div>

</x-filament-panels::page>
