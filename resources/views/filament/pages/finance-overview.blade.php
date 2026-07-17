<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $wallet = $this->walletSummary();
        $refunds = $this->refundSummary();
        $payments = $this->paymentSummary();
        $instructor = $this->instructorSummary();
    @endphp

    <div class="space-y-6">

        @include('filament.pages.partials.financial-filter-bar', ['freshness' => $freshness])

        {{-- ── Payment collection (requires payment permission) ──────────── --}}
        @if($payments !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">External payment collection (period)</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Attempts: <span class="font-semibold text-gray-950 dark:text-white">{{ $payments->attempts }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Captured: <span class="font-semibold text-gray-950 dark:text-white">{{ $payments->captured }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Success rate: <span class="font-semibold text-gray-950 dark:text-white">{{ $payments->successRate !== null ? $payments->successRate.'%' : 'N/A' }}</span></span>
                    @foreach($payments->capturedAmountByCurrency as $currency => $minor)
                        <span class="text-gray-700 dark:text-gray-300">Collected ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                    @endforeach
                    <span class="text-gray-700 dark:text-gray-300">Open reconciliation issues: <span class="font-semibold {{ $payments->openReconciliationIssues > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $payments->openReconciliationIssues }}</span></span>
                </div>
            </div>
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Payment collection details require the payment-report permission.</p>
            </div>
        @endif

        {{-- ── Wallet (requires wallet permission) ───────────────────────── --}}
        @if($wallet !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Wallet</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    @forelse($wallet->currentLiabilityByCurrency as $currency => $minor)
                        <span class="text-gray-700 dark:text-gray-300">Current liability ({{ $currency }}, as of now): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                    @empty
                        <span class="text-gray-500 dark:text-gray-400">No wallets exist yet.</span>
                    @endforelse
                    <span class="text-gray-700 dark:text-gray-300">Balance/ledger mismatches: <span class="font-semibold {{ $wallet->balanceMismatchCount > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $wallet->balanceMismatchCount }}</span></span>
                    @if($refunds !== null)
                        <span class="text-gray-700 dark:text-gray-300">Refunds awaiting manual review: <span class="font-semibold {{ $refunds->manualReviewCount > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $refunds->manualReviewCount }}</span></span>
                    @endif
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Wallet debits are consumption of already-collected value — never added to external collections.</p>
            </div>
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Wallet details require the wallet-report permission.</p>
            </div>
        @endif

        {{-- ── Instructor financial obligations (strictest permission) ───── --}}
        @if($instructor !== null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Instructor financial obligations</h3>
                <div class="mt-3 flex flex-wrap gap-6 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Earnings created (period): <span class="font-semibold text-gray-950 dark:text-white">{{ $instructor->earningsCreatedCount }}</span></span>
                    @foreach($instructor->unallocatedReleasableByCurrency as $currency => $minor)
                        <span class="text-gray-700 dark:text-gray-300">Releasable, unallocated ({{ $currency }}): <span class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\MoneyFormatter::format($minor, $currency) }}</span></span>
                    @endforeach
                    <span class="text-gray-700 dark:text-gray-300">Pending withdrawals: <span class="font-semibold text-gray-950 dark:text-white">{{ ($instructor->withdrawalsByStatus['submitted'] ?? 0) + ($instructor->withdrawalsByStatus['under_review'] ?? 0) }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Failed payout attempts: <span class="font-semibold {{ ($instructor->payoutAttemptsByStatus['failed'] ?? 0) > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $instructor->payoutAttemptsByStatus['failed'] ?? 0 }}</span></span>
                    <span class="text-gray-700 dark:text-gray-300">Open payout reconciliation issues: <span class="font-semibold {{ $instructor->openPayoutReconciliationIssues > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $instructor->openPayoutReconciliationIssues }}</span></span>
                </div>
            </div>
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Instructor compensation details require the instructor-compensation report permission.</p>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            No recognized-revenue figure is displayed: the platform has no authoritative revenue-recognition definition (§7 Outcome B).
            Collections, commercial value and wallet consumption are deliberately separate and are never summed together or across currencies.
        </p>

    </div>

</x-filament-panels::page>
