<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $supply = $this->supply();
        $demand = $this->demand();
        $comparison = $this->comparison();
    @endphp

    <div class="space-y-6">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
                Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
                Period: {{ $freshness->periodLabel }} ·
                Supply figures are current-state; demand figures are period events.
            </p>
        </div>

        {{-- ── Filters ─────────────────────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Period (demand)</label>
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

                <div class="flex items-end">
                    <button wire:click="resetFilters" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5">
                        Reset filters
                    </button>
                </div>
            </div>
        </div>

        {{-- ── Supply (current-state) ──────────────────────────────────── --}}
        @if($supply !== null)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Instructors (all)' => $supply->totalInstructors,
                    'Active' => $supply->activeInstructors,
                    'Approved (not yet active)' => $supply->approvedInstructors,
                    'On vacation' => $supply->onVacation,
                    'Suspended' => $supply->suspended,
                    'Active w/o availability' => $supply->activeWithoutPublishedAvailability,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Active instructors by subject (current assignment)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($supply->bySubject as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No active instructor has a current subject assignment.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Active instructors by country (current profile)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($supply->byCountry as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No active instructors match these filters.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Published weekly availability hours are reported on the Instructor Performance report (their calculation owner).
                No utilization rate is computed — current availability is never a historical denominator.
            </p>
        @endif

        {{-- ── Demand (period events) ──────────────────────────────────── --}}
        @if($demand !== null)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Bookings in period' => $demand->bookingsInPeriod,
                    'Students with bookings' => $demand->studentsWithBookings,
                    'Demo bookings' => $demand->demoBookings,
                    'Paid bookings' => $demand->paidBookings,
                    'Weekly recurring' => $demand->byRecurrence['weekly'] ?? 0,
                    'Unknown historical' => $demand->byRecurrence['unknown_historical'] ?? 0,
                ] as $label => $value)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Booking demand by subject</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($demand->bySubject as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No bookings match this period.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Booking demand by country (student profile)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($demand->byCountry as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No bookings match this period.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Bookings by weekday (reporting timezone)</h3>
                    <div class="mt-3 space-y-1 text-sm">
                        @foreach($demand->byWeekday as $day => $count)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $day }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Active learning-goal interest by subject</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Interest signal — currently active goals, not bookings.</p>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($demand->activeGoalDemandBySubject as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No active learning goals exist.</p>
                        @endforelse
                    </div>
                </div>

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Preferred-subject interest (self-selected preference)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">A stated preference — never treated as booked demand.</p>
                    <div class="mt-3 space-y-1 text-sm">
                        @forelse($demand->preferredSubjectInterest as $row)
                            <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                        @empty
                            <p class="text-gray-500 dark:text-gray-400">No preferred subjects are recorded.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Comparison (compatible dimensions only) ─────────────────── --}}
        @if($comparison !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Supply ↔ demand comparison</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Bookings per active instructor (period ÷ current): <span class="font-semibold text-gray-950 dark:text-white">{{ $comparison->demandPerActiveInstructor !== null ? $comparison->demandPerActiveInstructor : 'N/A (no active instructors)' }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Active instructors without bookings in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $comparison->activeInstructorsWithoutBookings }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Countries with demand, no active supply: <span class="font-semibold {{ count($comparison->countriesWithDemandNoSupply) > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ count($comparison->countriesWithDemandNoSupply) > 0 ? implode(', ', $comparison->countriesWithDemandNoSupply) : 'None' }}</span></span>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2">Subject gap</th>
                                <th class="px-4 py-2">Bookings in period</th>
                                <th class="px-4 py-2">Active instructors (current)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($comparison->subjectGaps as $row)
                                <tr>
                                    <td class="px-4 py-2">{{ $row->subjectLabel }}</td>
                                    <td class="px-4 py-2 {{ $row->bookingsInPeriod === 0 ? 'text-warning-600' : '' }}">{{ $row->bookingsInPeriod }}</td>
                                    <td class="px-4 py-2 {{ $row->activeInstructors === 0 ? 'text-danger-600' : '' }}">{{ $row->activeInstructors }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-4 text-gray-500 dark:text-gray-400">Every subject with activity has both demand and active supply.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Factual mismatch listing only — no supply-demand score, no instructor ranking. Search events, profile
                    views and waitlists are not recorded in this platform version, so unmet demand is never inferred from them.
                </p>
            </div>
        @endif

    </div>

</x-filament-panels::page>
