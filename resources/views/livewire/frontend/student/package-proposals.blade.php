<div class="space-y-6">
    @if ($statusMessage)
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ $statusMessage }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    @if ($pendingFakeCheckout)
        <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
            Test checkout started ({{ $pendingFakeCheckout['reference'] }}). No real payment is taken by the test provider.
        </div>
    @endif

    <x-account.card>
        <div class="divide-y divide-white/[0.06]">
            @forelse ($proposals as $proposal)
                @php($purchase = $purchases->get($proposal->id))
                @php($entitlement = $entitlements->get($proposal->id))
                @php($openAttempt = $purchase?->payments->first(fn ($payment) => $payment->status->isOpen()))
                @php($isActivating = $purchase && in_array($purchase->proposal_id, $awaitingActivation, true))
                <div class="py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ $proposal->packageBenefitRule?->name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">with {{ $proposal->instructor?->name }}</p>
                        </div>
                        {{-- Once accepted, the purchase's status is the meaningful one: the offer is settled, the money is not. --}}
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold
                            {{ $purchase?->status->value === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200' : ($purchase ? 'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200') }}">
                            {{ $purchase?->status->label() ?? $proposal->status->label() }}
                        </span>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        <div><dt class="text-xs text-slate-500">Total lessons</dt><dd class="text-white font-semibold">{{ $proposal->total_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Paid lessons</dt><dd class="text-white font-semibold">{{ $proposal->paid_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Bonus lessons</dt><dd class="text-white font-semibold">{{ $proposal->bonus_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Price</dt><dd class="text-emerald-400 font-bold">{{ \App\Support\MoneyFormatter::format($purchase?->amount_minor ?? $proposal->final_price_minor, $purchase?->currency_code ?? $proposal->currency_code) }}</dd></div>
                    </dl>

                    {{-- The live lesson balance is the entitlement's, and an entitlement exists only once payment has settled. --}}
                    @if ($entitlement)
                        <div class="mt-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Your lessons</p>
                                {{-- Expired and Completed are different endings: time ran out, versus every lesson was used. --}}
                                <span class="text-xs font-semibold
                                    {{ $entitlement->status->value === 'active' ? 'text-emerald-300' : ($entitlement->status->value === 'expired' ? 'text-rose-300' : 'text-slate-300') }}">
                                    @if ($entitlement->status->value === 'expired')
                                        Expired {{ $entitlement->expires_at?->format('j F Y') }} · {{ $entitlement->remaining_quantity }} unused
                                    @elseif ($entitlement->status->value === 'completed')
                                        All {{ $entitlement->total_quantity }} lessons used
                                    @else
                                        {{ $entitlement->status->label() }}
                                    @endif
                                </span>
                            </div>
                            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                                <div><dt class="text-xs text-slate-500">Total</dt><dd class="text-white font-semibold">{{ $entitlement->total_quantity }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Used</dt><dd class="text-white font-semibold">{{ $entitlement->used_quantity }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Remaining</dt><dd class="text-emerald-400 font-bold">{{ $entitlement->remaining_quantity }}</dd></div>
                                {{-- The validity clock started when payment activated the package. --}}
                                <div>
                                    <dt class="text-xs text-slate-500">Valid until</dt>
                                    <dd class="text-white font-semibold">{{ $entitlement->expires_at?->format('j F Y') ?? 'No expiry' }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endif

                    {{-- Money confirmed, activation still catching up. Never offer to pay again. --}}
                    @if ($isActivating)
                        <div class="mt-4 rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">
                            Payment received. Your package is being activated — this usually takes a moment.
                        </div>
                    @endif

                    @if ($proposal->status->value === 'approved' && ! $purchase)
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="accept('{{ $proposal->id }}')" wire:loading.attr="disabled" wire:target="accept('{{ $proposal->id }}')"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                                Accept &amp; Continue to Payment
                            </button>
                            <button type="button" wire:click="decline('{{ $proposal->id }}')" wire:loading.attr="disabled" wire:target="decline('{{ $proposal->id }}')"
                                wire:confirm="Decline this package offer? Your instructor can send a new one later."
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 hover:text-white transition disabled:opacity-50">
                                Reject
                            </button>
                            <p class="w-full text-xs text-slate-500">Your lessons become available once payment is complete.</p>
                        </div>
                    @endif

                    @if ($purchase && $purchase->status->isPayable() && ! $isActivating)
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="pay('{{ $purchase->id }}')" wire:loading.attr="disabled" wire:target="pay('{{ $purchase->id }}')"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-emerald-500 hover:bg-emerald-400 transition disabled:opacity-50">
                                {{ $openAttempt ? 'Continue Payment' : 'Pay Now' }}
                            </button>
                            {{-- Only offered when there is actually something open to abandon. --}}
                            @if ($openAttempt)
                                <button type="button" wire:click="cancelPaymentAttempt('{{ $purchase->id }}')" wire:loading.attr="disabled" wire:target="cancelPaymentAttempt('{{ $purchase->id }}')"
                                    wire:confirm="Cancel this payment? You can start a new one afterwards."
                                    class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 hover:text-white transition disabled:opacity-50">
                                    Cancel Payment Attempt
                                </button>
                            @endif
                            <p class="w-full text-xs text-slate-500">Reference {{ $purchase->reference }} · Your lessons unlock once this payment is confirmed.</p>
                        </div>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <h3 class="text-slate-300 font-semibold mb-2">No packages yet</h3>
                    <p class="text-slate-400 text-sm max-w-xs">When an instructor offers you a personalized lesson package and it's approved, it will appear here.</p>
                </div>
            @endforelse
        </div>

        @if ($proposals->hasPages())
            <div class="mt-4">{{ $proposals->links() }}</div>
        @endif
    </x-account.card>
</div>
