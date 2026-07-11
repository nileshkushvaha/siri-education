{{-- The instructor's full agreement chain — previous agreements remain
     permanently available; nothing here is deletable. --}}
<div class="space-y-3 text-sm">
    @forelse ($agreements as $agreement)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="flex items-center justify-between gap-4">
                <span class="font-mono">{{ $agreement->reference }} (v{{ $agreement->version }})</span>
                <span>{{ $agreement->status->label() }}</span>
            </div>
            <div class="mt-1 text-gray-500 dark:text-gray-400">
                {{ $agreement->pay_basis->shortLabel() }}
                · {{ \App\Support\MoneyFormatter::format($agreement->amount_minor, $agreement->currency_code) }}
                · {{ $agreement->effective_from->format('M j, Y') }} → {{ $agreement->effective_until?->format('M j, Y') ?? 'open' }}
                @if ($agreement->supersedes_agreement_id)
                    · replaces {{ $agreement->supersedes?->reference }}
                @endif
            </div>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">No agreements yet.</p>
    @endforelse
</div>
