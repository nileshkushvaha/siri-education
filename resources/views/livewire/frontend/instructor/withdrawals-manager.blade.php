<div class="space-y-6">
    @if (session('withdrawals-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ session('withdrawals-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    {{-- Balances --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @forelse ($balances as $balance)
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Available ({{ $balance->currencyCode }})</p>
                <p class="mt-2 text-2xl font-bold text-emerald-400">
                    {{ \App\Support\MoneyFormatter::format($balance->availableMinor, $balance->currencyCode) }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Reserved: {{ \App\Support\MoneyFormatter::format($balance->reservedMinor, $balance->currencyCode) }}
                    &middot; Minimum withdrawal: {{ \App\Support\MoneyFormatter::format($balance->minimumWithdrawalMinor, $balance->currencyCode) }}
                    @if ($balance->maximumWithdrawalMinor !== null)
                        &middot; Maximum: {{ \App\Support\MoneyFormatter::format($balance->maximumWithdrawalMinor, $balance->currencyCode) }}
                    @endif
                </p>
                @if (! $balance->canWithdraw && $balance->blockingReason)
                    <p class="mt-2 text-xs text-amber-300">{{ $balance->blockingReason }}</p>
                @endif
            </x-account.card>
        @empty
            <x-account.card class="sm:col-span-3">
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <h3 class="text-slate-300 font-semibold mb-2">No released earnings yet</h3>
                    <p class="text-slate-400 text-sm max-w-xs">Earnings become available for withdrawal after their hold period ends.</p>
                </div>
            </x-account.card>
        @endforelse
    </div>

    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Withdrawal requests</h2>
        @if ($eligible && ! $showForm)
            <button type="button" wire:click="openForm"
                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                Request withdrawal
            </button>
        @endif
    </div>

    @unless ($eligible)
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">Withdrawals not available yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">{{ $ineligibilityReason }}</p>
            </div>
        </x-account.card>
    @endunless

    @if ($showForm)
        <x-account.card>
            @if (! $confirming)
                <h3 class="text-white font-semibold mb-4">Request a withdrawal</h3>

                @if ($methods->isEmpty())
                    <p class="text-sm text-slate-400">
                        You need a <a href="{{ route('dashboard.instructor.payout-methods') }}" class="text-indigo-300 underline">verified payout method</a> before you can request a withdrawal.
                    </p>
                @else
                    <form wire:submit="confirm" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Payout method</label>
                            <select wire:model.live="payoutMethodId"
                                class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none">
                                @foreach ($methods as $method)
                                    <option value="{{ $method->id }}">{{ $method->display_label }} ({{ $method->currency_code }}){{ $method->is_default ? ' — default' : '' }}</option>
                                @endforeach
                            </select>
                            @error('payoutMethodId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Amount</label>
                            <input type="text" wire:model="amount" inputmode="decimal" placeholder="0.00" autocomplete="off"
                                class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                            @error('amount') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Note (optional)</label>
                            <input type="text" wire:model="note" maxlength="500" autocomplete="off"
                                class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                            @error('note') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2 flex items-center gap-3 pt-2">
                            <button type="submit" wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                                Continue
                            </button>
                            <button type="button" wire:click="cancelForm"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                Cancel
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <h3 class="text-white font-semibold mb-4">Confirm your withdrawal</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Amount</dt>
                        <dd class="text-white font-semibold">{{ $amount }} {{ $selectedMethod?->currency_code }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Destination</dt>
                        <dd class="text-white">{{ $selectedMethod?->display_label }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Fee</dt>
                        <dd class="text-white">{{ $selectedMethod ? \App\Support\MoneyFormatter::format(0, $selectedMethod->currency_code) : '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-slate-400">
                    The final amount is validated against your live balance when you confirm. Your request will be reviewed before payment.
                </p>
                <div class="mt-6 flex items-center gap-3">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-emerald-500 hover:bg-emerald-400 transition disabled:opacity-50">
                        <span wire:loading.remove wire:target="submit">Confirm request</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>
                    <button type="button" wire:click="back"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                        Back
                    </button>
                </div>
            @endif
        </x-account.card>
    @endif

    {{-- History --}}
    <x-account.card>
        @forelse ($withdrawals as $withdrawal)
            <div wire:key="withdrawal-{{ $withdrawal->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white font-mono">{{ $withdrawal->reference }}</p>
                            <x-ui.badge :color="$withdrawal->status->color()">{{ $withdrawal->status->label() }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-slate-400">
                            {{ viewer_datetime($withdrawal->requested_at) }}
                            &middot; {{ $withdrawal->payout_method_label }}
                        </p>
                        @if ($withdrawal->status === \App\Earnings\Enums\InstructorWithdrawalStatus::Rejected && $withdrawal->rejection_reason)
                            <p class="mt-1 text-xs text-rose-300">Rejected: {{ $withdrawal->rejection_reason }}</p>
                        @elseif ($withdrawal->status === \App\Earnings\Enums\InstructorWithdrawalStatus::Failed)
                            <p class="mt-1 text-xs text-rose-300">{{ $withdrawal->failure_reason ?: 'This payout could not be completed. Our team has been notified.' }}</p>
                        @elseif ($withdrawal->status === \App\Earnings\Enums\InstructorWithdrawalStatus::Reversed)
                            <p class="mt-1 text-xs text-rose-300">This payout was returned by the receiving bank after being paid. Our finance team is reviewing it.</p>
                        @elseif ($withdrawal->status === \App\Earnings\Enums\InstructorWithdrawalStatus::Processing)
                            <p class="mt-1 text-xs text-slate-400">Your payout is being processed.</p>
                        @elseif ($withdrawal->status === \App\Earnings\Enums\InstructorWithdrawalStatus::Paid)
                            <p class="mt-1 text-xs text-emerald-300">Paid{{ $withdrawal->paid_at ? ' on '.viewer_date($withdrawal->paid_at) : '' }}.</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-sm font-semibold text-white">
                                {{ \App\Support\MoneyFormatter::format($withdrawal->amount_minor, $withdrawal->currency_code) }}
                            </p>
                            <p class="text-xs text-slate-400">
                                Fee {{ \App\Support\MoneyFormatter::format($withdrawal->fee_minor, $withdrawal->currency_code) }} &middot; Net {{ \App\Support\MoneyFormatter::format($withdrawal->net_amount_minor, $withdrawal->currency_code) }}
                            </p>
                        </div>
                        @if ($withdrawal->status->isInstructorCancellable())
                            <button type="button" wire:click="cancelRequest('{{ $withdrawal->id }}')" wire:loading.attr="disabled"
                                wire:confirm="Cancel this withdrawal request? The reserved amount becomes available again."
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-rose-300 border border-rose-400/30 hover:bg-rose-500/10 transition">
                                Cancel
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No withdrawal requests yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">When you request a withdrawal it will appear here with its live status.</p>
            </div>
        @endforelse

        @if ($withdrawals->hasPages())
            <div class="pt-4">
                {{ $withdrawals->links() }}
            </div>
        @endif
    </x-account.card>
</div>
