<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $referrals = $this->referralActivity();
        $rates = $this->reviewQualityRates();
        $notifications = $this->notificationActivity();
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
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Currency (referrals)</label>
                    <input type="text" maxlength="3" placeholder="All (e.g. INR)" wire:model.live="currencyCode" class="mt-1 w-full rounded-lg border-gray-300 text-sm uppercase dark:bg-gray-800 dark:border-white/10" />
                </div>

                <div class="flex items-end">
                    <button wire:click="resetFilters" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5">
                        Reset filters
                    </button>
                </div>
            </div>
        </div>

        {{-- ── Referral activity (ViewReferralReports) ─────────────────── --}}
        @if($referrals !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Referral rewards (wallet-ledger confirmed)</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Credits executed in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $referrals->creditsExecutedInPeriod }}</span></span>
                    @foreach($referrals->creditedAmountByCurrency as $currency => $minor)
                        <span class="text-gray-700 dark:text-gray-300">Credited ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                    @endforeach
                    <span class="text-gray-700 dark:text-gray-300">Distinct recipients: <span class="font-semibold text-gray-950 dark:text-white">{{ $referrals->distinctRecipientsInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Reversals: <span class="font-semibold {{ $referrals->reversalsInPeriod > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $referrals->reversalsInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Referral module: <span class="font-semibold text-gray-950 dark:text-white">{{ $referrals->referralModuleEnabled ? 'Enabled' : 'Disabled' }}</span></span>
                </div>
                {{-- Phase 19D — reward-lifecycle and attribution figures from the Referral domain --}}
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Attributions in period: <span class="font-semibold text-gray-950 dark:text-white">{{ $referrals->attributionsInPeriod }}</span></span>
                    @foreach($referrals->rewardsByStatus as $status => $count)
                        <span class="text-gray-700 dark:text-gray-300">Rewards {{ str_replace('_', ' ', $status) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                    @endforeach
                    @foreach($referrals->reversedRewardAmountByCurrency as $currency => $minor)
                        <span class="text-gray-700 dark:text-gray-300">Reversed ({{ $currency }}): <span class="font-semibold text-danger-600">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                    @endforeach
                    <span class="text-gray-700 dark:text-gray-300">Open held/failed queue: <span class="font-semibold {{ $referrals->heldOrFailedRewardsOpen > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $referrals->heldOrFailedRewardsOpen }}</span></span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Credited value is counted only when the wallet ledger confirms it; lifecycle counts come from the
                    referral reward records and sign-ups from attribution records. Referral conversion rate remains
                    unavailable (no agreed qualifying-event denominator), referred booking value is never labeled
                    revenue, and no ROI figure is ever computed.
                </p>
            </div>
        @endif

        {{-- ── Review & quality rates (ViewReviewQualityReports) ───────── --}}
        @if($rates !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Review submission &amp; quality overview</h3>
                <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 text-sm">
                    @foreach([
                        'Submission rate' => $rates->submissionRate !== null ? $rates->submissionRate.'%' : 'N/A (no concluded windows)',
                        'Demo review rate' => $rates->demoSubmissionRate !== null ? $rates->demoSubmissionRate.'%' : 'N/A',
                        'Paid-lesson review rate' => $rates->paidSubmissionRate !== null ? $rates->paidSubmissionRate.'%' : 'N/A',
                        'Platform average rating' => $rates->platformAverageRating !== null ? number_format($rates->platformAverageRating, 2) : 'N/A (no eligible reviews)',
                        'Published eligible reviews' => $rates->publishedEligibleReviewCount,
                        'Concluded windows (period)' => $rates->concludedWindowsInPeriod,
                    ] as $label => $value)
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap gap-6 text-xs text-gray-500 dark:text-gray-400">
                    <span>Pending review reports (now): <span class="font-semibold {{ $rates->pendingReviewReports > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $rates->pendingReviewReports }}</span></span>
                    <span>Open quality alerts (now): <span class="font-semibold {{ $rates->openQualityAlerts > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $rates->openQualityAlerts }}</span></span>
                    <span>Revoked windows excluded: {{ $rates->revokedExcludedInPeriod }}</span>
                    <span>Manual-review windows excluded: {{ $rates->manualReviewExcludedInPeriod }}</span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Rates cover eligibility windows opened in the period that have concluded (submitted or expired).
                    The platform rating reuses the Phase 17 published-rating aggregate — hidden, rejected, archived and
                    private-feedback reviews never contribute.
                    @if($this->qualityDashboardUrl())
                        Moderation, report and alert queues live on the
                        <a href="{{ $this->qualityDashboardUrl() }}" class="text-primary-600 hover:underline dark:text-primary-400">Reviews &amp; Quality Dashboard</a>.
                    @endif
                </p>
            </div>
        @endif

        {{-- ── Notification activity (ViewNotificationReports) ─────────── --}}
        @if($notifications !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Notification activity</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">In-app created (period): <span class="font-semibold text-gray-950 dark:text-white">{{ $notifications->inAppCreatedInPeriod }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Read (of period cohort, as of now): <span class="font-semibold text-gray-950 dark:text-white">{{ $notifications->inAppReadOfPeriodCohort }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Read rate: <span class="font-semibold text-gray-950 dark:text-white">{{ $notifications->readRate !== null ? $notifications->readRate.'%' : 'N/A (none created)' }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Currently unread (all time): <span class="font-semibold text-gray-950 dark:text-white">{{ $notifications->currentUnread }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Deduplicated dispatch claims (period): <span class="font-semibold text-gray-950 dark:text-white">{{ $notifications->dedupClaimsInPeriod }}</span></span>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div>
                        <h4 class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">In-app by type (period)</h4>
                        <div class="mt-2 space-y-1 text-sm">
                            @forelse($notifications->byType as $row)
                                <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                            @empty
                                <p class="text-gray-500 dark:text-gray-400">No in-app notifications were created in this period.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h4 class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Duplicate-prevention claims by class (period)</h4>
                        <div class="mt-2 space-y-1 text-sm">
                            @forelse($notifications->dedupByClass as $row)
                                <div class="flex justify-between"><span class="text-gray-700 dark:text-gray-300">{{ $row->label }}</span><span class="font-semibold text-gray-950 dark:text-white">{{ $row->count }}</span></div>
                            @empty
                                <p class="text-gray-500 dark:text-gray-400">No dispatch claims were recorded in this period.</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Version 1 records in-app notifications and dispatch idempotency claims only. No delivery attempt,
                    channel outcome, provider callback or retry state is stored — delivery rate, provider performance,
                    email/SMS/WhatsApp outcomes and failure metrics are unavailable and are never inferred from missing
                    callbacks. Notification payloads are never displayed.
                </p>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Messaging analytics are unavailable: Version 1 has no conversation or message domain, and messaging activity
            is never inferred from notification records.
        </p>

    </div>

</x-filament-panels::page>
