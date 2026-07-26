<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $summary = $this->summary();
    @endphp

    <div class="space-y-6">

        @include('filament.pages.partials.financial-filter-bar', ['freshness' => $freshness])

        {{-- ── Earnings (period events + current-state liability) ────────── --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Earnings created in period — {{ $summary->earningsCreatedCount }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->earningsCreatedAmountByCurrency as $currency => $minor)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $currency }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No instructor earnings match this period.</p>
                    @endforelse
                </div>
            </div>

            {{-- GAP-008 — demo-to-paid conversion incentive awards created in period --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Demo conversion incentive awards in period — {{ $summary->demoConversionIncentiveAwardsCount }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->demoConversionIncentiveAmountByCurrency as $currency => $minor)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $currency }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No demo-conversion incentive awards match this period.</p>
                    @endforelse
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current earning liability by status (as of now)</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->earningLiabilityByStatusCurrency as $status => $byCurrency)
                        @foreach($byCurrency as $currency => $minor)
                            <div class="flex items-center justify-between px-6 py-2 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', ucfirst($status)) }} · {{ $currency }}</span>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                            </div>
                        @endforeach
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No instructor earnings exist yet.</p>
                    @endforelse
                    @foreach($summary->unallocatedReleasableByCurrency as $currency => $minor)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">Releasable, unallocated · {{ $currency }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ── Settlements ───────────────────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Settlement batches (created in period)</h3>
            <div class="mt-3 flex flex-wrap gap-4 text-sm">
                @forelse($summary->settlementsByStatus as $status => $count)
                    <span class="text-gray-700 dark:text-gray-300">{{ ucfirst($status) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                @empty
                    <span class="text-gray-500 dark:text-gray-400">No settlement batches in this period.</span>
                @endforelse
            </div>
            @foreach($summary->settlementAmountByCurrency as $currency => $minor)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Batch total ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></p>
            @endforeach
            <p class="mt-2 text-xs {{ $summary->settlementAllocationMismatchCount > 0 ? 'text-danger-600' : 'text-gray-500 dark:text-gray-400' }}">
                Allocation mismatches (batch total ≠ allocated earnings, all time): {{ $summary->settlementAllocationMismatchCount }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">A settlement batch is an internal grouping — never an external transfer, never a payout.</p>
        </div>

        {{-- ── Withdrawals & payouts ─────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Withdrawal requests (requested in period)</h3>
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    @forelse($summary->withdrawalsByStatus as $status => $count)
                        <span class="text-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', ucfirst($status)) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                    @empty
                        <span class="text-gray-500 dark:text-gray-400">No withdrawal requests match these filters.</span>
                    @endforelse
                </div>
                @foreach($summary->withdrawalRequestedAmountByCurrency as $currency => $minor)
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Requested ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></p>
                @endforeach
                @foreach($summary->withdrawalPaidAmountByCurrency as $currency => $minor)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Paid net, by paid date ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></p>
                @endforeach
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">An approved withdrawal is not a successful payout — payout execution is reported separately.</p>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Payout attempts (created in period)</h3>
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    @forelse($summary->payoutAttemptsByStatus as $status => $count)
                        <span class="text-gray-700 dark:text-gray-300">{{ str_replace('_', ' ', ucfirst($status)) }}: <span class="font-semibold text-gray-950 dark:text-white">{{ $count }}</span></span>
                    @empty
                        <span class="text-gray-500 dark:text-gray-400">No payout attempts in this period.</span>
                    @endforelse
                </div>
                <p class="mt-3 text-xs {{ $summary->openPayoutReconciliationIssues > 0 ? 'text-danger-600' : 'text-gray-500 dark:text-gray-400' }}">
                    Open payout reconciliation issues (now): {{ $summary->openPayoutReconciliationIssues }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Provider success is never inferred from a local processing state.</p>
            </div>
        </div>

    </div>

</x-filament-panels::page>
