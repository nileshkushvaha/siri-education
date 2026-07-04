<div>
    <div class="mb-4 flex items-center justify-end">
        <select wire:model.live="statusFilter"
                class="rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-300 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <x-account.card>
        @forelse($history as $booking)
            <div wire:key="booking-history-{{ $booking->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">with {{ $booking->host?->name ?? 'Teacher' }}</p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-sm font-medium text-slate-300">{{ $booking->starts_at->format('M j, Y') }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $booking->starts_at->format('g:i A') }}</p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No bookings found</h3>
                <p class="text-slate-400 text-sm max-w-xs">Your booking history will appear here.</p>
            </div>
        @endforelse

        @if($history->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $history->links() }}
            </div>
        @endif
    </x-account.card>
</div>
