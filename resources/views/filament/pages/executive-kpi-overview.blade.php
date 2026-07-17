<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $kpis = $this->overview();
    @endphp

    <div class="space-y-6">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
                Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
                Period: {{ $freshness->periodLabel }} ·
                Every figure below is produced by its owning report — this page adds no calculation of its own.
            </p>
        </div>

        {{-- ── Period filter (deliberately the only filter) ────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
            </div>
        </div>

        @if($kpis !== null)

            {{-- ── Platform activity ───────────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Platform activity</h3>
                @if($kpis->students !== null || $kpis->instructorLifecycle !== null || $kpis->lessons !== null)
                    <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 text-sm">
                        @if($kpis->students !== null)
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">Students (all)</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->students->totalStudents }}</p></div>
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">New students (period)</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->students->newInPeriod }}</p></div>
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">Engaged (period)</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->students->engagedInPeriod }}</p></div>
                        @endif
                        @if($kpis->instructorLifecycle !== null)
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">Instructors (all)</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->instructorLifecycle->total }}</p></div>
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">Active instructors</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->instructorLifecycle->byStatus['active'] ?? 0 }}</p></div>
                            <div><p class="text-xs text-gray-500 dark:text-gray-400">New instructor accounts</p><p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $kpis->instructorLifecycle->newAccountsInPeriod }}</p></div>
                        @endif
                    </div>
                    @if($kpis->instructorActivity !== null)
                        <div class="mt-3 flex flex-wrap gap-6 text-xs text-gray-500 dark:text-gray-400">
                            <span>Completed lessons: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->completedLessons }}</span></span>
                            <span>Demo bookings: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->demoBookings }}</span></span>
                            <span>Paid bookings: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->paidBookings }}</span></span>
                            <span>Student no-shows: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->studentNoShows }}</span></span>
                            <span>Instructor no-shows: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->instructorNoShows }}</span></span>
                            <span>Technical-issue lessons: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorActivity->technicalIssues }}</span></span>
                        </div>
                    @endif
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Requires the student, instructor or booking report permissions.</p>
                @endif
            </div>

            {{-- ── Learning ────────────────────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Learning</h3>
                @if($kpis->learningPlans !== null)
                    <div class="mt-3 flex flex-wrap gap-6 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">Active plans (now): <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->learningPlans->currentByStatus['active'] ?? 0 }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Plans completed (period): <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->learningPlans->completedInPeriod }}</span></span>
                        @if($kpis->homework !== null)
                            <span class="text-gray-700 dark:text-gray-300">Homework assigned: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->homework->assignedInPeriod }}</span></span>
                            <span class="text-gray-700 dark:text-gray-300">Homework submitted: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->homework->submittedInPeriod }}</span></span>
                            <span class="text-gray-700 dark:text-gray-300">Currently overdue: <span class="font-semibold {{ $kpis->homework->currentlyOverdue > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $kpis->homework->currentlyOverdue }}</span></span>
                        @endif
                        @if($kpis->milestonesReviews !== null)
                            <span class="text-gray-700 dark:text-gray-300">Milestones achieved: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->milestonesReviews->milestonesAchievedInPeriod }}</span></span>
                            <span class="text-gray-700 dark:text-gray-300">Progress reviews completed: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->milestonesReviews->reviewsCompletedInPeriod }}</span></span>
                        @endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Requires the learning report permission.</p>
                @endif
            </div>

            {{-- ── Quality ─────────────────────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Quality</h3>
                @if($kpis->quality !== null)
                    <div class="mt-3 flex flex-wrap gap-6 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">Published eligible reviews: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->quality->publishedEligibleReviewCount }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Platform rating: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->quality->platformAverageRating !== null ? number_format($kpis->quality->platformAverageRating, 2) : 'N/A' }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Review submission rate: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->quality->submissionRate !== null ? $kpis->quality->submissionRate.'%' : 'N/A' }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Pending review reports: <span class="font-semibold {{ $kpis->quality->pendingReviewReports > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $kpis->quality->pendingReviewReports }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Open quality alerts: <span class="font-semibold {{ $kpis->quality->openQualityAlerts > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $kpis->quality->openQualityAlerts }}</span></span>
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Requires the review-quality report permission.</p>
                @endif
            </div>

            {{-- ── Finance (each block behind its own permission) ──────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Finance</h3>
                @if($kpis->payments !== null)
                    <div class="mt-3 flex flex-wrap gap-6 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">Captured payments: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->payments->captured }}</span></span>
                        @foreach($kpis->payments->capturedAmountByCurrency as $currency => $minor)
                            <span class="text-gray-700 dark:text-gray-300">Collected ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                        @endforeach
                        @foreach($kpis->payments->grossPaidBookingValueByCurrency as $currency => $minor)
                            <span class="text-gray-700 dark:text-gray-300">Gross paid-booking value ({{ $currency }}, not revenue): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Payment figures require the payment-report permission.</p>
                @endif
                @if($kpis->wallet !== null)
                    <div class="mt-2 flex flex-wrap gap-6 text-sm">
                        @foreach($kpis->wallet->currentLiabilityByCurrency as $currency => $minor)
                            <span class="text-gray-700 dark:text-gray-300">Wallet liability ({{ $currency }}, as of now): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                        @endforeach
                        @if($kpis->refunds !== null)
                            <span class="text-gray-700 dark:text-gray-300">Refund credits executed: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->refunds->executedCount }}</span></span>
                            <span class="text-gray-700 dark:text-gray-300">Refunds in manual review: <span class="font-semibold {{ $kpis->refunds->manualReviewCount > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $kpis->refunds->manualReviewCount }}</span></span>
                        @endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Wallet figures require the wallet-report permission.</p>
                @endif
                @if($kpis->instructorFinancials !== null)
                    <div class="mt-2 flex flex-wrap gap-6 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">Instructor earnings created (period): <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->instructorFinancials->earningsCreatedCount }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Failed payout attempts: <span class="font-semibold {{ ($kpis->instructorFinancials->payoutAttemptsByStatus['failed'] ?? 0) > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $kpis->instructorFinancials->payoutAttemptsByStatus['failed'] ?? 0 }}</span></span>
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Instructor compensation requires its own dedicated permission.</p>
                @endif
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    No recognized revenue, net revenue or margin exists (Phase 18E §7 Outcome B). Amounts are per currency and never combined.
                </p>
            </div>

            {{-- ── Communication ───────────────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Communication</h3>
                @if($kpis->notifications !== null)
                    <div class="mt-3 flex flex-wrap gap-6 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">In-app notifications created: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->notifications->inAppCreatedInPeriod }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Currently unread: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->notifications->currentUnread }}</span></span>
                        <span class="text-gray-700 dark:text-gray-300">Dispatch dedup claims: <span class="font-semibold text-gray-950 dark:text-white">{{ $kpis->notifications->dedupClaimsInPeriod }}</span></span>
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Requires the notification report permission.</p>
                @endif
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Delivery rate, provider performance and messaging analytics are unavailable — those sources do not exist in this platform version (Phase 18G).</p>
            </div>

        @endif

    </div>

</x-filament-panels::page>
