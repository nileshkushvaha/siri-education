<div class="space-y-6">
    <div>
        @forelse ($summary as $currency)
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-account.stat-card label="Total Earned ({{ $currency->currencyCode }})"
                    :value="\App\Support\MoneyFormatter::format($currency->totalEarnedMinor, $currency->currencyCode)"
                    gradient="from-indigo-500 to-violet-500"
                    icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2m0-14a9 9 0 100 18 9 9 0 000-18z" />
                <x-account.stat-card label="Pending"
                    :value="\App\Support\MoneyFormatter::format($currency->pendingHoldMinor, $currency->currencyCode)"
                    gradient="from-amber-500 to-orange-500"
                    icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                <x-account.stat-card label="Released"
                    :value="\App\Support\MoneyFormatter::format($currency->releasableMinor, $currency->currencyCode)"
                    gradient="from-emerald-500 to-teal-500"
                    icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </div>
        @empty
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-account.stat-card label="Total Earned" value="—" gradient="from-indigo-500 to-violet-500"
                    icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2m0-14a9 9 0 100 18 9 9 0 000-18z" />
                <x-account.stat-card label="Pending" value="—" gradient="from-amber-500 to-orange-500"
                    icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                <x-account.stat-card label="Released" value="—" gradient="from-emerald-500 to-teal-500"
                    icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </div>
        @endforelse
    </div>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-indigo-300">Recent Lessons</h2>
        <x-account.card>
            @forelse ($earnings as $earning)
                <div wire:key="earning-{{ $earning->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-sm font-medium text-white truncate">{{ $earning->subject?->name ?? 'General' }}</p>
                                <x-ui.badge :color="$earning->status->color()">{{ $earning->status->label() }}</x-ui.badge>
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ viewer_date($earning->lesson?->starts_at) ?? viewer_date($earning->created_at) }}
                                &middot; {{ $earning->student?->name ?? 'Student' }}
                            </p>
                            @if ($earning->settlementBatch)
                                <p class="text-xs text-slate-500 mt-1">Settlement: {{ $earning->settlementBatch->batch_reference }} &middot; {{ $earning->settlementBatch->status->label() }}</p>
                            @endif
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-sm font-bold text-white">{{ \App\Support\MoneyFormatter::format($earning->earning_amount_minor, $earning->currency_code) }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <x-ui.empty-state title="No earnings yet" description="Completed lessons will appear here once earnings are generated." />
            @endforelse

            @if ($earnings->hasPages())
                <div class="mt-6 pt-4 border-t border-white/[0.04]">
                    {{ $earnings->links() }}
                </div>
            @endif
        </x-account.card>
    </div>
</div>
