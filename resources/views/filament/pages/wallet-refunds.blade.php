<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $summary = $this->summary();
        $refunds = $this->refundSummary();
    @endphp

    <div class="space-y-6">

        @include('filament.pages.partials.financial-filter-bar', ['freshness' => $freshness])

        <div class="flex justify-end">
            @include('filament.pages.partials.report-export-button', ['exportKey' => 'wallet_refund_linkage_rows', 'label' => 'Refund linkage rows'])
        </div>

        {{-- Drill-down anchor: the dashboard links here with ?section=wallet. --}}
        <div id="section-wallet" class="scroll-mt-24">
            @if ($this->isActiveSection('wallet'))
                <p class="mb-2 inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30">
                    <x-filament::icon icon="heroicon-m-arrow-down-circle" class="h-3.5 w-3.5" />
                    You were sent to: Wallet activity
                </p>
            @endif
        </div>
        {{-- ── Current-state wallet metrics (as-of, never period-scoped) ── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current wallet liability (as of {{ $summary->liabilityAsOf }})</h3>
            </div>
            <div class="grid grid-cols-2 gap-0 sm:grid-cols-4 divide-x divide-gray-100 dark:divide-white/5">
                @forelse($summary->currentLiabilityByCurrency as $currency => $minor)
                    <div class="px-4 py-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $currency }}</p>
                        <p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</p>
                    </div>
                @empty
                    <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 col-span-4">No wallets exist yet.</p>
                @endforelse
            </div>
            <div class="px-6 py-3 border-t border-gray-100 dark:border-white/5 text-xs text-gray-500 dark:text-gray-400">
                Wallets: <span class="font-semibold text-gray-950 dark:text-white">{{ $summary->walletCount }}</span> ·
                Positive balance: <span class="font-semibold text-gray-950 dark:text-white">{{ $summary->positiveBalanceWallets }}</span> ·
                Zero balance: <span class="font-semibold text-gray-950 dark:text-white">{{ $summary->zeroBalanceWallets }}</span> ·
                Low balance (below the currency's alert threshold): <span class="font-semibold text-gray-950 dark:text-white">{{ $summary->lowBalanceWallets }}</span> ·
                Balance/ledger mismatches: <span class="font-semibold {{ $summary->balanceMismatchCount > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $summary->balanceMismatchCount }}</span>
            </div>
        </div>

        {{-- ── Period-event ledger movements ─────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Ledger movements in period</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Recharge, refund, referral and promotional credits are distinct types; a debit is wallet consumption, never external collection. Reversals in period: {{ $summary->reversalCount }}.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-2">Currency</th>
                            <th class="px-4 py-2">Entry type</th>
                            <th class="px-4 py-2">Direction</th>
                            <th class="px-4 py-2">Count</th>
                            <th class="px-4 py-2">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($summary->movements as $row)
                            <tr>
                                <td class="px-6 py-2">{{ $row['currency'] }}</td>
                                <td class="px-4 py-2">{{ str_replace('_', ' ', ucfirst($row['entryType'])) }}</td>
                                <td class="px-4 py-2">{{ ucfirst($row['direction']) }}</td>
                                <td class="px-4 py-2">{{ $row['count'] }}</td>
                                <td class="px-4 py-2">{{ \App\Support\MoneyFormatter::format($row['amountMinor'], $row['currency']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-4 text-gray-500 dark:text-gray-400">No wallet movement matches these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Drill-down anchor: the dashboard links here with ?section=refunds. --}}
        <div id="section-refunds" class="scroll-mt-24">
            @if ($this->isActiveSection('refunds'))
                <p class="mb-2 inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30">
                    <x-filament::icon icon="heroicon-m-arrow-down-circle" class="h-3.5 w-3.5" />
                    You were sent to: Refunds
                </p>
            @endif
        </div>
        {{-- ── Refunds ───────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">Refund decisions in period</p>
                <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $refunds->refundDecisionsInPeriod }}</p>
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">Pending execution (now)</p>
                <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $refunds->pendingExecution }}</p>
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">Executed wallet credits</p>
                <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $refunds->executedCount }}</p>
                @foreach($refunds->executedAmountByCurrency as $currency => $minor)
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\MoneyFormatter::format($minor, $currency) }} {{ $currency }}</p>
                @endforeach
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">Manual review (now)</p>
                <p class="mt-1 text-xl font-bold {{ $refunds->manualReviewCount > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $refunds->manualReviewCount }}</p>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">Version 1 lesson-refund policy is wallet credit only — no gateway refund is inferred or reported here.</p>

        {{-- ── Refund linkage table ──────────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Refund linkage</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-2">Booking</th>
                            <th class="px-4 py-2">Outcome</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Reason</th>
                            <th class="px-4 py-2">Amount</th>
                            <th class="px-4 py-2">Decided</th>
                            <th class="px-4 py-2">Executed</th>
                            <th class="px-4 py-2">Payment ref</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($this->refundLinkage() as $row)
                            <tr>
                                <td class="px-6 py-2">{{ $row->bookingReference }}</td>
                                <td class="px-4 py-2">{{ $row->lessonOutcomeLabel }}</td>
                                <td class="px-4 py-2">{{ $row->processingStatusLabel }}{{ $row->adminHold ? ' (hold)' : '' }}</td>
                                <td class="px-4 py-2">{{ $row->reasonCode ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->amountMinor !== null && $row->currency !== null ? \App\Support\MoneyFormatter::format($row->amountMinor, $row->currency).' '.$row->currency : '—' }}</td>
                                <td class="px-4 py-2">{{ $row->decidedAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                                <td class="px-4 py-2">{{ $row->executedAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->maskedPaymentReference ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">No refund credits were executed in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3">{{ $this->refundLinkage()?->links() }}</div>
        </div>

    </div>

    @include('filament.pages.partials.report-section-scroll')

</x-filament-panels::page>
