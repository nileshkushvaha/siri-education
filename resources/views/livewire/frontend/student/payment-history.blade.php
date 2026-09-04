<div>
    <x-account.card>
        @forelse($payments as $booking)
            @php
                // A booking that can still be paid is one that is still
                // alive AND whose payment is pending or failed — the same
                // rule My Bookings uses to show its "Pay now" button.
                $bookingIsActive = ! $booking->status->isTerminal();
                $canCompletePayment = $bookingIsActive && $booking->payment_status->isPayable();
                // "Pending" with no reference means checkout was never
                // started at the gateway: nothing is actually pending.
                $neverStarted = $booking->payment_status === \App\Booking\Enums\BookingPaymentStatus::Pending && blank($booking->payment_reference);
            @endphp
            <div wire:key="payment-{{ $booking->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-fg-strong truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        @if($neverStarted && $bookingIsActive)
                            <x-ui.badge color="warning">Payment not started</x-ui.badge>
                        @elseif($neverStarted)
                            <x-ui.badge color="gray">Not paid</x-ui.badge>
                        @else
                            <x-ui.badge :color="$booking->payment_status->color()">{{ $booking->payment_status->label() }}</x-ui.badge>
                        @endif
                        @if(! $bookingIsActive && $booking->payment_status->isPayable())
                            <span class="text-xs text-fg-muted">&middot; booking {{ strtolower($booking->status->label()) }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-fg-muted">
                        {{ viewer_date($booking->starts_at) }}
                        @if($booking->payment_reference)
                            &middot; Ref: {{ $booking->payment_reference }}
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-right">
                    @if($booking->price !== null)
                        <p class="text-sm font-semibold text-fg-strong">{{ $booking->currency }} {{ number_format((float) $booking->price, 2) }}</p>
                    @endif
                    @if($canCompletePayment)
                        <a href="{{ route('dashboard.my-bookings', ['booking' => $booking->id]) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-indigo-600 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" data-complete-payment>
                            Complete payment
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </a>
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
