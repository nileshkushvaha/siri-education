<x-filament-panels::page>

    @php($overview = $this->overview())

    <div class="space-y-6">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Live query · Period: {{ $overview?->periodLabel }} ·
                Every figure is produced by the AI evaluation report from <code>ai_runs</code>, each feature's own outcome
                record, and reviewer verdicts — this page adds no calculation of its own.
            </p>
        </div>

        {{-- ── Period filter ───────────────────────────────────────────── --}}
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

        @if($overview === null)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">No evaluation data available.</p>
            </div>
        @else

            {{-- ── Platform state and spend ────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Platform state &amp; spend</h2>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">AI module</p>
                        <p class="text-lg font-semibold {{ $overview->aiEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500' }}">
                            {{ $overview->aiEnabled ? 'Enabled' : 'Disabled' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $overview->enabledCapabilities === [] ? 'No capabilities on' : implode(', ', $overview->enabledCapabilities) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Cost this period</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->money($overview->totalCost, $overview->costCurrency) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($overview->totalRuns()) }} runs</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Spent today</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->money($overview->spentToday, $overview->costCurrency) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $overview->dailyLimit === null ? 'No daily limit' : 'Limit '.number_format($overview->dailyLimit, 2).' · '.$this->percent($overview->dailyBudgetUsedRatio()).' used' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Spent this month</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->money($overview->spentThisMonth, $overview->costCurrency) }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $overview->monthlyLimit === null ? 'No monthly limit' : 'Limit '.number_format($overview->monthlyLimit, 2).' · '.$this->percent($overview->monthlyBudgetUsedRatio()).' used' }}
                        </p>
                    </div>
                </div>
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Costs are ESTIMATES from the admin-maintained price table — for budgeting, never for reconciliation against an invoice.
                </p>
            </div>

            {{-- ── Per-feature evaluation ──────────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Feature evaluation</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Acceptance means different things per feature — a used draft, an approved summary, a confirmed finding —
                    because "did a human take this seriously" is the only question comparable across them. Each row names its own.
                </p>

                @if($overview->features === [])
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No AI activity in this period.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-gray-500 dark:text-gray-400">
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 text-left font-semibold">Feature</th>
                                    <th class="py-2 text-right font-semibold">Runs</th>
                                    <th class="py-2 text-right font-semibold">Failed</th>
                                    <th class="py-2 text-right font-semibold">Awaiting review</th>
                                    <th class="py-2 text-right font-semibold">Accepted</th>
                                    <th class="py-2 text-right font-semibold">Rejected</th>
                                    <th class="py-2 text-right font-semibold">Acceptance</th>
                                    <th class="py-2 text-right font-semibold">Found useful</th>
                                    <th class="py-2 text-right font-semibold">Cost</th>
                                    <th class="py-2 text-right font-semibold">Cost / accepted</th>
                                    <th class="py-2 text-right font-semibold">Median latency</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($overview->features as $feature)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2.5 font-medium text-gray-950 dark:text-white">
                                            {{ $feature->featureLabel }}
                                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                                {{ $feature->acceptedLabel }} vs {{ $feature->rejectedLabel }}
                                            </span>
                                        </td>
                                        <td class="py-2.5 text-right">{{ number_format($feature->runs) }}</td>
                                        <td class="py-2.5 text-right">{{ number_format($feature->failed + $feature->rejected) }}</td>
                                        <td class="py-2.5 text-right">{{ number_format($feature->awaitingHuman) }}</td>
                                        <td class="py-2.5 text-right">{{ number_format($feature->acceptedOutcomes) }}</td>
                                        <td class="py-2.5 text-right">{{ number_format($feature->rejectedOutcomes) }}</td>
                                        <td class="py-2.5 text-right font-semibold">{{ $this->percent($feature->acceptanceRate()) }}</td>
                                        <td class="py-2.5 text-right">
                                            {{ $this->percent($feature->helpfulRate()) }}
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ $feature->helpfulVerdicts + $feature->notHelpfulVerdicts }} verdicts
                                            </span>
                                        </td>
                                        <td class="py-2.5 text-right">{{ $this->money($feature->estimatedCost, $overview->costCurrency) }}</td>
                                        <td class="py-2.5 text-right">{{ $this->money($feature->costPerAcceptedOutcome(), $overview->costCurrency) }}</td>
                                        <td class="py-2.5 text-right">{{ $feature->medianLatencyMs === null ? '—' : number_format($feature->medianLatencyMs).' ms' }}</td>
                                    </tr>
                                    @if($feature->notHelpfulReasons !== [])
                                        <tr class="border-b border-gray-100 dark:border-white/5">
                                            <td colspan="11" class="pb-3 text-xs text-gray-500 dark:text-gray-400">
                                                Why reviewers marked it unhelpful:
                                                @foreach($feature->notHelpfulReasons as $reason => $count)
                                                    <span class="ml-1">{{ str_replace('_', ' ', $reason) }} ({{ $count }})</span>@if(! $loop->last) · @endif
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Prompt version comparison ───────────────────────────── --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Prompt version performance</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Compare versions of the SAME prompt only. Rows with too few runs are marked — acting on a rate drawn from a
                    handful of runs is how a prompt gets changed because of noise.
                </p>

                @if($overview->promptVersions === [])
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No prompt activity in this period.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-gray-500 dark:text-gray-400">
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 text-left font-semibold">Prompt</th>
                                    <th class="py-2 text-right font-semibold">Runs</th>
                                    <th class="py-2 text-right font-semibold">Acceptance</th>
                                    <th class="py-2 text-right font-semibold">Found useful</th>
                                    <th class="py-2 text-right font-semibold">Cost</th>
                                    <th class="py-2 text-left font-semibold">Evidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($overview->promptVersions as $prompt)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2.5 font-medium text-gray-950 dark:text-white">
                                            {{ $prompt->promptKey }}:{{ $prompt->promptVersion }}
                                        </td>
                                        <td class="py-2.5 text-right">{{ number_format($prompt->runs) }}</td>
                                        <td class="py-2.5 text-right font-semibold">{{ $this->percent($prompt->acceptanceRate()) }}</td>
                                        <td class="py-2.5 text-right">{{ $this->percent($prompt->helpfulRate()) }}</td>
                                        <td class="py-2.5 text-right">{{ $this->money($prompt->estimatedCost, $overview->costCurrency) }}</td>
                                        <td class="py-2.5 text-left text-xs {{ $prompt->hasEnoughEvidence() ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                            {{ $prompt->hasEnoughEvidence() ? 'Enough to compare' : 'Too few runs to act on' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        @endif
    </div>
</x-filament-panels::page>
