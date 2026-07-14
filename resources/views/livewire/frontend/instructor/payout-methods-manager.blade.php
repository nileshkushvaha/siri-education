<div class="space-y-6">
    @if (session('payout-methods-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ session('payout-methods-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Your payout methods</h2>
        @if ($eligible && ! $showForm)
            <button type="button" wire:click="startCreate"
                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                Add bank account
            </button>
        @endif
    </div>

    @unless ($eligible)
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">Payout methods not available yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">{{ $ineligibilityReason }}</p>
            </div>
        </x-account.card>
    @endunless

    @if ($showForm)
        <x-account.card>
            <h3 class="text-white font-semibold mb-1">{{ $editingMethodId ? 'Correct bank details' : 'Add a bank account' }}</h3>
            <p class="text-xs text-slate-400 mb-6">
                For your security we never show stored bank details, so all fields must be entered in full.
                Details are encrypted and reviewed by our team before the account can receive payouts.
            </p>

            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Account holder name *</label>
                    <input type="text" wire:model="account_holder_name" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('account_holder_name') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Bank name</label>
                    <input type="text" wire:model="bank_name" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('bank_name') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Branch name</label>
                    <input type="text" wire:model="branch_name" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('branch_name') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Account number</label>
                    <input type="text" wire:model="account_number" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('account_number') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">IFSC / routing number</label>
                    <input type="text" wire:model="routing_number" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('routing_number') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">IBAN (if applicable)</label>
                    <input type="text" wire:model="iban" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('iban') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">SWIFT / BIC</label>
                    <input type="text" wire:model="swift_bic" autocomplete="off"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none" />
                    @error('swift_bic') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Account type</label>
                    <select wire:model="account_type"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none">
                        <option value="">—</option>
                        <option value="savings">Savings</option>
                        <option value="current">Current / Checking</option>
                    </select>
                    @error('account_type') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Country</label>
                    <select wire:model="country_id"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none">
                        <option value="">—</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                        @endforeach
                    </select>
                    @error('country_id') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">Currency *</label>
                    <select wire:model="currency_code"
                        class="w-full rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-sm text-white focus:border-indigo-400 focus:outline-none">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>
                        @endforeach
                    </select>
                    @error('currency_code') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2 flex items-center gap-3 pt-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">Save draft</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    <button type="button" wire:click="cancelForm"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                        Cancel
                    </button>
                </div>
            </form>
        </x-account.card>
    @endif

    <x-account.card>
        @forelse ($methods as $method)
            <div wire:key="method-{{ $method->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $method->display_label }}</p>
                            <x-ui.badge :color="$method->status->color()">{{ $method->status->label() }}</x-ui.badge>
                            @if ($method->is_default)
                                <x-ui.badge color="info">Default</x-ui.badge>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400">
                            {{ $method->type->label() }} &middot; {{ $method->currency_code }}
                            @if ($method->country) &middot; {{ $method->country->name }} @endif
                            &middot; Added {{ $method->created_at->format('M j, Y') }}
                        </p>
                        @if ($method->status === \App\Earnings\Enums\PayoutMethodStatus::Rejected && $method->rejection_reason)
                            <p class="mt-1 text-xs text-rose-300">Rejected: {{ $method->rejection_reason }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($method->status->isEditable())
                            <button type="button" wire:click="startEdit('{{ $method->id }}')"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                {{ $method->status === \App\Earnings\Enums\PayoutMethodStatus::Rejected ? 'Correct details' : 'Edit' }}
                            </button>
                            @if ($method->status === \App\Earnings\Enums\PayoutMethodStatus::Draft)
                                <button type="button" wire:click="submitForVerification('{{ $method->id }}')" wire:loading.attr="disabled"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                                    Submit for verification
                                </button>
                            @endif
                        @endif
                        @if ($method->status === \App\Earnings\Enums\PayoutMethodStatus::Verified && ! $method->is_default)
                            <button type="button" wire:click="makeDefault('{{ $method->id }}')" wire:loading.attr="disabled"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                Make default
                            </button>
                        @endif
                        @if ($method->status->isActive())
                            <button type="button" wire:click="disableMethod('{{ $method->id }}')" wire:loading.attr="disabled"
                                wire:confirm="Disable this payout method? It can no longer be used for new withdrawals."
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-rose-300 border border-rose-400/30 hover:bg-rose-500/10 transition">
                                Disable
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No payout methods yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Add a bank account to receive your earnings. Details are encrypted and verified before use.</p>
            </div>
        @endforelse
    </x-account.card>

    <p class="text-xs text-slate-500">
        We never display stored bank details. Verified details cannot be edited — add a replacement account to change banks.
    </p>
</div>
