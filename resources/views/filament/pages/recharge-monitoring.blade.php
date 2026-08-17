<x-filament-panels::page>

    @php
        $freshness = $this->freshness();
        $summary = $this->summary();
        $rows = $this->rows();
    @endphp

    <div class="space-y-6">

        @include('filament.pages.partials.financial-filter-bar', ['freshness' => $freshness])

        {{-- ── Recharge-specific filters ───────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Status</label>
                    <select wire:model.live="rechargeStatus" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->statusOptions() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Provider</label>
                    <select wire:model.live="rechargeProvider" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                        <option value="">All</option>
                        @foreach($this->providerOptions() as $provider)
                            <option value="{{ $provider }}">{{ ucfirst($provider) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Internal reference</label>
                    <input type="text" maxlength="100" placeholder="WRCH-..." wire:model.live.debounce.400ms="rechargeReference" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10" />
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <input type="checkbox" wire:model.live="capturedUncreditedOnly" class="rounded border-gray-300 dark:bg-gray-800 dark:border-white/10" />
                        Captured &amp; uncredited only
                    </label>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <input type="checkbox" wire:model.live="staleOnly" class="rounded border-gray-300 dark:bg-gray-800 dark:border-white/10" />
                        Stale only
                    </label>
                </div>
            </div>
            <div class="mt-4">
                <button type="button" wire:click="resetRechargeFilters" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                    Reset all filters
                </button>
            </div>
        </div>

        {{-- ── Current-state operational summary (as-of, never period-scoped) ── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Attempt health (as of {{ $summary->generatedAtIso }})</h3>
            </div>
            <div class="grid grid-cols-2 gap-0 sm:grid-cols-4 lg:grid-cols-6 divide-x divide-y sm:divide-y-0 divide-gray-100 dark:divide-white/5">
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Awaiting payment</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $summary->awaitingPayment }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Captured, credit pending</p>
                    <p class="mt-0.5 text-lg font-bold text-warning-600">{{ $summary->capturedCreditPending }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Captured, credit failed</p>
                    <p class="mt-0.5 text-lg font-bold {{ $summary->capturedCreditFailed > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">{{ $summary->capturedCreditFailed }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Succeeded</p>
                    <p class="mt-0.5 text-lg font-bold text-success-600">{{ $summary->succeeded }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Provider terminal failures</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-950 dark:text-white">{{ $summary->providerTerminalFailures }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Stale attempts</p>
                    <p class="mt-0.5 text-lg font-bold {{ $summary->stale > 0 ? 'text-warning-600' : 'text-gray-950 dark:text-white' }}">{{ $summary->stale }}</p>
                </div>
            </div>
        </div>

        {{-- ── Recharge attempts table ─────────────────────────────────────── --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Recharge attempts</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Read-only. No credit, success, refund, retry, or status action exists on this page — the scheduled reconciliation command is the only automated retry mechanism.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-2">Reference</th>
                            <th class="px-4 py-2">Student</th>
                            <th class="px-4 py-2">Provider</th>
                            <th class="px-4 py-2">Amount</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Classification</th>
                            <th class="px-4 py-2">Provider confirmed</th>
                            <th class="px-4 py-2">Last synced</th>
                            <th class="px-4 py-2">Age</th>
                            <th class="px-4 py-2">Failure</th>
                            <th class="px-4 py-2">Provider order ref</th>
                            <th class="px-4 py-2">Provider payment ref</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($rows as $row)
                            <tr wire:key="recharge-{{ $row->id }}">
                                <td class="px-6 py-2 font-mono text-xs">{{ $row->reference }}</td>
                                <td class="px-4 py-2">{{ $row->studentLabel }}</td>
                                <td class="px-4 py-2">{{ $row->provider ? ucfirst($row->provider) : '—' }}</td>
                                <td class="px-4 py-2">{{ \App\Support\MoneyFormatter::format($row->amountMinor, $row->currencyCode) }}</td>
                                <td class="px-4 py-2">{{ $row->status->label() }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ match($row->classification->color()) {
                                            'success' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
                                            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400',
                                            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
                                            'info' => 'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400',
                                            default => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
                                        } }}">
                                        {{ $row->classification->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">{{ $row->providerConfirmedAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->lastSyncedAtUtc?->timezone($freshness->reportingTimezone)->format('M j, Y H:i') ?? 'Never' }}</td>
                                <td class="px-4 py-2">{{ $row->createdAtUtc->diffForHumans(null, true) }}</td>
                                <td class="px-4 py-2">{{ $row->failureCode ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row->maskedProviderOrderId ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row->maskedProviderPaymentId ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="px-6 py-4 text-gray-500 dark:text-gray-400">No recharge attempts match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3">{{ $rows->links() }}</div>
        </div>

    </div>

</x-filament-panels::page>
