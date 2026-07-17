<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $summary = $this->summary();
    @endphp

    <div class="space-y-6">

        @include('filament.pages.partials.financial-filter-bar', ['freshness' => $freshness])

        <div class="flex justify-end">
            @include('filament.pages.partials.report-export-button', ['exportKey' => 'payment_reconciliation_rows', 'label' => 'Reconciliation rows'])
        </div>

        {{-- ── Attempt outcomes ──────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach([
                'Attempts' => $summary->attempts,
                'Captured' => $summary->captured,
                'Failed' => $summary->failed,
                'Pending / in flight' => $summary->pending,
                'Cancelled / expired' => $summary->cancelledOrExpired,
                'Success rate' => $summary->successRate !== null ? $summary->successRate.'%' : 'N/A (no terminal attempts)',
            ] as $label => $value)
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Success rate = captured-at-some-point attempts ÷ terminal attempts (captured/failed/cancelled/expired/refunded). In-flight attempts are not yet outcomes.
        </p>

        {{-- ── Collections by currency (never a cross-currency total) ────── --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Successful external collections (captured, by paid attempt)</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->capturedAmountByCurrency as $currency => $minor)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $currency }} (avg {{ \App\Support\MoneyFormatter::format($summary->averageCapturedByCurrency[$currency] ?? 0, $currency) }})</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No payments match this period.</p>
                    @endforelse
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Gross paid-booking value (commercial value — not revenue, not cash collected)</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->grossPaidBookingValueByCurrency as $currency => $minor)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $currency }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No paid bookings match this period.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ── Providers & exceptions ────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">By provider &amp; status</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($summary->byProviderStatus as $row)
                        <div class="flex items-center justify-between px-6 py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ ucfirst($row['provider']) }} · {{ ucfirst($row['status']) }}</span>
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $row['count'] }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No payments match this period.</p>
                    @endforelse
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Exceptions</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Duplicate provider events (period)</dt><dd class="font-semibold text-gray-950 dark:text-white">{{ $summary->duplicateProviderEvents }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Open reconciliation issues (now)</dt><dd class="font-semibold {{ $summary->openReconciliationIssues > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $summary->openReconciliationIssues }}</dd></div>
                </dl>
            </div>
        </div>

        {{-- ── Reconciliation queue (read-only) ──────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Open payment reconciliation issues</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Read-only — issues are resolved on the existing reconciliation resource, never from this report.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-2">Reference</th>
                            <th class="px-4 py-2">Provider</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Severity</th>
                            <th class="px-4 py-2">Amount</th>
                            <th class="px-4 py-2">Summary</th>
                            <th class="px-4 py-2">Detected</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($this->reconciliationIssues() as $row)
                            <tr>
                                <td class="px-6 py-2">
                                    @if($row->drillDownUrl)
                                        <a href="{{ $row->drillDownUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row->reference }}</a>
                                    @else
                                        {{ $row->reference }}
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ ucfirst($row->provider) }}</td>
                                <td class="px-4 py-2">{{ $row->typeLabel }}</td>
                                <td class="px-4 py-2">{{ $row->severityLabel }}</td>
                                <td class="px-4 py-2">{{ $row->amountMinor !== null && $row->currency !== null ? \App\Support\MoneyFormatter::format($row->amountMinor, $row->currency).' '.$row->currency : '—' }}</td>
                                <td class="px-4 py-2 max-w-md truncate">{{ $row->safeSummary }}</td>
                                <td class="px-4 py-2">{{ $row->firstDetectedAtUtc->timezone($freshness->reportingTimezone)->format('M j, Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">No reconciliation issues found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3">{{ $this->reconciliationIssues()?->links() }}</div>
        </div>

    </div>

</x-filament-panels::page>
