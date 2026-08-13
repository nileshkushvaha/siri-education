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

    <x-account.card>
        <div class="divide-y divide-white/[0.06]">
            @forelse ($proposals as $proposal)
                <div class="py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ $proposal->packageBenefitRule?->name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">with {{ $proposal->instructor?->name }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold
                            {{ $proposal->status->value === 'accepted' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200' }}">
                            {{ $proposal->status->label() }}
                        </span>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        <div><dt class="text-xs text-slate-500">Total lessons</dt><dd class="text-white font-semibold">{{ $proposal->total_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Paid lessons</dt><dd class="text-white font-semibold">{{ $proposal->paid_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Bonus lessons</dt><dd class="text-white font-semibold">{{ $proposal->bonus_quantity }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Final price</dt><dd class="text-emerald-400 font-bold">{{ \App\Support\MoneyFormatter::format($proposal->final_price_minor, $proposal->currency_code) }}</dd></div>
                    </dl>

                    @if ($proposal->status->value === 'approved')
                        <div class="mt-4">
                            <button type="button" wire:click="accept('{{ $proposal->id }}')" wire:loading.attr="disabled" wire:target="accept('{{ $proposal->id }}')"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                                Accept package
                            </button>
                            <p class="mt-1.5 text-xs text-slate-500">Payment is handled separately — accepting records your agreement to this package.</p>
                        </div>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <h3 class="text-slate-300 font-semibold mb-2">No packages yet</h3>
                    <p class="text-slate-400 text-sm max-w-xs">When an instructor proposes a personalized lesson package and it's approved, it will appear here.</p>
                </div>
            @endforelse
        </div>

        @if ($proposals->hasPages())
            <div class="mt-4">{{ $proposals->links() }}</div>
        @endif
    </x-account.card>
</div>
