<div>
    <x-account.card>
        @forelse($invoices as $invoice)
            <div wire:key="invoice-{{ $invoice->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $invoice->invoice_number }}</p>
                    </div>
                    <p class="text-xs text-slate-400 truncate">
                        {{ $invoice->service_description }}
                        &middot; {{ $invoice->issued_at->format('M j, Y') }}
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4 flex items-center gap-4">
                    <p class="text-sm font-semibold text-white">{{ \App\Support\MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code) }}</p>
                    <a href="{{ route('dashboard.invoices.download', $invoice) }}"
                       class="inline-flex min-h-11 items-center px-3 py-2 rounded-lg bg-white/[0.06] text-xs font-semibold text-white hover:bg-white/[0.1] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                        Download
                    </a>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No invoices yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Receipts for successful payments and wallet recharges will appear here.</p>
            </div>
        @endforelse

        @if($invoices->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $invoices->links() }}
            </div>
        @endif
    </x-account.card>
</div>
