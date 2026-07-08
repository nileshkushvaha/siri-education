<div class="space-y-6">
    @if($wallet)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total balance</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Available</p>
                <p class="mt-2 text-2xl font-bold text-emerald-400">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->available_balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Held</p>
                <p class="mt-2 text-2xl font-bold text-amber-400">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->held_balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
        </div>

        @if($wallet->status->value !== 'active')
            <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
                This wallet is currently <strong>{{ $wallet->status->label() }}</strong>.
            </div>
        @endif
    @else
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No wallet yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Your wallet will be set up once recharge is available.</p>
            </div>
        </x-account.card>
    @endif

    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Statement</h2>
        <button type="button" disabled title="Coming soon" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-500 bg-white/[0.03] border border-white/[0.06] cursor-not-allowed">
            Recharge (Coming soon)
        </button>
    </div>

    <x-account.card>
        @forelse($entries as $entry)
            <div wire:key="ledger-{{ $entry->id }}" class="flex items-center justify-between py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $entry->entry_type->label() }}</p>
                        <x-ui.badge :color="$entry->status->color()">{{ $entry->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">
                        {{ $entry->created_at->format('M j, Y g:i A') }}
                        @if($entry->description)
                            &middot; {{ $entry->description }}
                        @endif
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-sm font-semibold {{ $entry->direction->value === 'credit' ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $entry->direction->value === 'credit' ? '+' : '-' }}{{ \App\Wallet\Support\WalletMoneyFormatter::format($entry->amount_minor, $entry->currency, $entry->currency_code) }}
                    </p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No wallet activity yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Credits, debits, and adjustments will appear here.</p>
            </div>
        @endforelse

        @if($entries->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $entries->links() }}
            </div>
        @endif
    </x-account.card>
</div>
