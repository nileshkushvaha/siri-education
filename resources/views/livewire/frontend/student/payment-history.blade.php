<div>
    <x-account.card>
        @forelse($payments as $booking)
            <div wire:key="payment-{{ $booking->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-fg-strong truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        <x-ui.badge :color="$booking->payment_status->color()">{{ $booking->payment_status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-fg-muted">
                        {{ viewer_date($booking->starts_at) }}
                        @if($booking->payment_reference)
                            &middot; Ref: {{ $booking->payment_reference }}
                        @endif
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    @if($booking->price !== null)
                        <p class="text-sm font-semibold text-fg-strong">{{ $booking->currency }} {{ number_format((float) $booking->price, 2) }}</p>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-fg-muted font-semibold mb-2">No payments yet</h3>
                <p class="text-fg-muted text-sm max-w-xs">Payments for paid sessions will appear here.</p>
            </div>
        @endforelse

        @if($payments->hasPages())
            <div class="mt-6 pt-4 border-t border-edge">
                {{ $payments->links() }}
            </div>
        @endif
    </x-account.card>
</div>
