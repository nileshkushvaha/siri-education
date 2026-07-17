{{-- Shared Phase 18E financial filter bar + freshness banner. Expects $freshness. --}}
<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-3">
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Live query · Generated {{ $freshness->generatedAt->toDayDateTimeString() }} ·
        Reporting timezone <span class="font-medium text-gray-950 dark:text-white">{{ $freshness->reportingTimezone }}</span> ·
        Period: {{ $freshness->periodLabel }} ·
        Amounts are shown per currency — cross-currency totals are not calculated.
    </p>
</div>

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
            <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Currency</label>
            <input type="text" maxlength="3" placeholder="All (e.g. INR)" wire:model.live="currencyCode" class="mt-1 w-full rounded-lg border-gray-300 text-sm uppercase dark:bg-gray-800 dark:border-white/10" />
        </div>

        @if(property_exists($this, 'walletTransactionType') && method_exists($this, 'entryTypeOptions'))
            <div>
                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Ledger entry type</label>
                <select wire:model.live="walletTransactionType" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800 dark:border-white/10">
                    <option value="">All</option>
                    @foreach($this->entryTypeOptions() as $type)
                        <option value="{{ $type->value }}">{{ str_replace('_', ' ', ucfirst($type->value)) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="mt-4">
        <button type="button" wire:click="resetFilters" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
            Reset filters
        </button>
    </div>
</div>
