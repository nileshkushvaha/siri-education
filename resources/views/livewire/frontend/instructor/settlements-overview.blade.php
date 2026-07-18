<div class="space-y-6">
    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-amber-300">Current Status</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @forelse ($pendingSettlement as $currency)
                <x-account.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pending Settlement ({{ $currency->currency_code }})</p>
                    <p class="mt-2 text-2xl font-bold text-amber-300">{{ \App\Support\MoneyFormatter::format((int) $currency->total_minor, $currency->currency_code) }}</p>
                </x-account.card>
            @empty
                <x-account.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pending Settlement</p>
                    <p class="mt-2 text-2xl font-bold text-slate-500">—</p>
                </x-account.card>
            @endforelse
        </div>
    </div>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-emerald-300">Previous Settlements</h2>
        <x-account.card>
            @forelse ($settlements as $batch)
                <div wire:key="settlement-{{ $batch->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-sm font-medium text-white truncate">{{ $batch->batch_reference }}</p>
                                <x-ui.badge :color="$batch->status->color()">{{ $batch->status->label() }}</x-ui.badge>
                            </div>
                            <p class="text-xs text-slate-400">
                                @if ($batch->period_start && $batch->period_end)
                                    {{ $batch->period_start->format('M j') }} – {{ $batch->period_end->format('M j, Y') }}
                                @endif
                                @if ($batch->paid_at)
                                    &middot; Processed {{ $batch->paid_at->format('M j, Y') }}
                                @endif
                            </p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-sm font-bold text-white">{{ \App\Support\MoneyFormatter::format($batch->total_amount_minor, $batch->currency_code) }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <x-ui.empty-state title="No settlements available yet" description="Your settlement history will appear here after processing." />
            @endforelse

            @if ($settlements->hasPages())
                <div class="mt-6 pt-4 border-t border-white/[0.04]">
                    {{ $settlements->links() }}
                </div>
            @endif
        </x-account.card>
    </div>
</div>
