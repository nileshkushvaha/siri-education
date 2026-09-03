<div class="space-y-6">
    @if($wallet)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Total balance</p>
                <p class="mt-2 text-2xl font-bold text-fg-strong">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Available</p>
                <p class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->available_balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Held</p>
                <p class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ \App\Wallet\Support\WalletMoneyFormatter::format($wallet->held_balance_minor, $wallet->currency, $wallet->currency_code) }}</p>
            </x-account.card>
        </div>

        @if($wallet->status->value !== 'active')
            <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-200">
                This wallet is currently <strong>{{ $wallet->status->label() }}</strong>.
            </div>
        @endif
    @else
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-fg-muted font-semibold mb-2">No wallet yet</h3>
                <p class="text-fg-muted text-sm max-w-xs">Recharge below to set up your wallet.</p>
            </div>
        </x-account.card>
    @endif

    <x-account.card>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-fg-muted">Recharge wallet</h2>

        @if($rechargeAvailable)
            <p class="mt-2 text-xs text-fg-muted">
                Currency: <span class="font-semibold text-fg-strong">{{ $rechargeCurrencyCode }}</span>
                {{-- Only advertise a limit this currency actually has configured; an
                     unconfigured limit is not enforced, so printing one would be a lie. --}}
                @if($rechargeLimits['min']) &middot; Min {{ $rechargeLimits['min'] }} @endif
                @if($rechargeLimits['max']) &middot; Max {{ $rechargeLimits['max'] }} @endif
            </p>

            @if($rechargeBanner)
                <div class="mt-3 rounded-xl border border-indigo-300/30 bg-indigo-400/10 px-4 py-3 text-sm text-indigo-700 dark:text-indigo-200" role="alert">
                    {{ $rechargeBanner }}
                </div>
            @endif

            <form wire:submit.prevent="initiateRecharge" class="mt-3 flex flex-wrap items-end gap-3">
                <div>
                    <label for="recharge-amount" class="block text-xs font-semibold text-fg-muted mb-1">Amount ({{ $rechargeCurrencyCode }})</label>
                    <input
                        type="text"
                        id="recharge-amount"
                        wire:model="rechargeAmount"
                        inputmode="decimal"
                        placeholder="e.g. 500"
                        class="w-40 rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                    />
                </div>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="initiateRecharge">
                    <span wire:loading.remove wire:target="initiateRecharge">Recharge</span>
                    <span wire:loading wire:target="initiateRecharge">Preparing payment...</span>
                </x-ui.button>
            </form>

            @if($pendingStripeRechargeId)
                {{-- wire:ignore: this subtree is polled by pollWalletRechargeStatus()
                     every few seconds while awaiting the webhook — Livewire must
                     never re-morph it, or the mounted Stripe Elements iframe
                     (DOM Livewire doesn't know about) would be torn down
                     mid-confirmation. --}}
                <div class="mt-3" wire:ignore>
                    <div id="wallet-recharge-stripe-payment-element" class="rounded-lg bg-white p-3"></div>
                    <p id="wallet-recharge-stripe-payment-errors" class="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300" role="alert"></p>
                    <x-ui.button type="button" id="wallet-recharge-stripe-confirm-button" class="mt-3 w-full justify-center" disabled>
                        Confirm card payment
                    </x-ui.button>
                </div>
            @endif

            @if($pendingFakeRecharge && app()->environment(['local', 'testing']))
                <div class="mt-3 rounded-lg border border-amber-300/20 bg-amber-400/10 p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">Test mode — fake provider</p>
                    <div class="mt-2 flex gap-2">
                        <x-ui.button type="button" size="sm" wire:click="simulateFakeRecharge(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="simulateFakeRecharge(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
                    </div>
                </div>
            @endif
        @else
            <p class="mt-2 text-xs text-fg-faint">Wallet recharge is not currently available for your account.</p>
        @endif
    </x-account.card>

    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-fg-muted">Statement</h2>
    </div>

    <x-account.card>
        @forelse($entries as $entry)
            <div wire:key="ledger-{{ $entry->id }}" class="flex items-center justify-between py-4 {{ ! $loop->last ? 'border-b border-edge' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-fg-strong truncate">{{ $entry->entry_type->label() }}</p>
                        <x-ui.badge :color="$entry->status->color()">{{ $entry->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-fg-muted">
                        {{ viewer_datetime($entry->created_at) }}
                        @if($entry->description)
                            &middot; {{ $entry->description }}
                        @endif
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-sm font-semibold {{ $entry->direction->value === 'credit' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                        {{ $entry->direction->value === 'credit' ? '+' : '-' }}{{ \App\Wallet\Support\WalletMoneyFormatter::format($entry->amount_minor, $entry->currency, $entry->currency_code) }}
                    </p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-fg-muted font-semibold mb-2">No wallet activity yet</h3>
                <p class="text-fg-muted text-sm max-w-xs">Credits, debits, and adjustments will appear here.</p>
            </div>
        @endforelse

        @if($entries->hasPages())
            <div class="mt-6 pt-4 border-t border-edge">
                {{ $entries->links() }}
            </div>
        @endif
    </x-account.card>
</div>

{{-- Both partials reference $wire, which only exists inside Livewire's
     own script scope. Included as bare <script> tags they ran at page
     load with $wire undefined, so neither checkout listener was ever
     registered and the console reported "$wire is not defined". Same
     @script/@endscript wrapping the booking wizard and booking history
     already use for their identical Razorpay/Stripe partials. --}}
@script
@include('livewire.frontend.student.partials.wallet-recharge-checkout-script')
@include('livewire.frontend.student.partials.wallet-recharge-stripe-checkout-script')
@endscript
