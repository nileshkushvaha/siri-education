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
            <div
                wire:key="booking-history-{{ $booking->id }}"
                wire:click="viewBooking('{{ $booking->id }}')"
                class="flex cursor-pointer items-center justify-between py-4 transition hover:bg-white/[0.03] {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">
                        with
                        @if($booking->host)
                            <a href="{{ route('instructors.show', $booking->host) }}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-indigo-300 hover:text-indigo-200 hover:underline">{{ $booking->host->name }}</a>
                        @else
                            Teacher
                        @endif
                    </p>
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

    <x-ui.modal id="booking-detail-modal" title="Booking details" size="md">
        @if($selectedBooking)
            @php
                $booking = $selectedBooking;
                $isActive = ! $booking->status->isTerminal();
            @endphp

            @if($modalBanner)
                <x-ui.alert type="error" class="mb-4">{{ $modalBanner }}</x-ui.alert>
            @endif

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Status</dt>
                    <dd class="mt-1"><x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Session</dt>
                    <dd class="mt-1 font-semibold text-white">{{ $booking->type?->name ?? 'Session' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">When</dt>
                    <dd class="mt-1 font-semibold text-white">{{ $booking->starts_at->timezone($booking->timezone)->format('M j, Y g:i A') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Timezone</dt>
                    <dd class="mt-1 font-semibold text-white">{{ $booking->timezone }}</dd>
                </div>
                @if(($booking->meta['subject'] ?? null) !== null)
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Subject</dt>
                        <dd class="mt-1 font-semibold capitalize text-white">{{ str_replace(['_', '-'], ' ', $booking->meta['subject']) }} @if($booking->meta['grade'] ?? null) &middot; Grade {{ $booking->meta['grade'] }} @endif</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Instructor</dt>
                    <dd class="mt-1 font-semibold">
                        @if($booking->host)
                            <a href="{{ route('instructors.show', $booking->host) }}" target="_blank" rel="noopener" class="text-indigo-300 hover:text-indigo-200 hover:underline">{{ $booking->host->name }}</a>
                        @else
                            <span class="text-white">Teacher</span>
                        @endif
                    </dd>
                </div>
                @if($booking->meeting_url && $booking->status->value === 'confirmed')
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Meeting</dt>
                        <dd class="mt-1"><a href="{{ $booking->meeting_url }}" target="_blank" rel="noopener" class="font-semibold text-indigo-300 underline underline-offset-2">Join link</a></dd>
                    </div>
                @endif
            </dl>

            @if($booking->status->value === 'cancelled')
                <p class="mt-4 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-300">
                    Cancelled
                    @if($booking->cancellation_reason)
                        &mdash; {{ $booking->cancellation_reason }}
                    @endif
                </p>
            @endif

            @if($booking->payment_status->value === 'paid')
                <p class="mt-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-300">Paid</p>
            @elseif($booking->payment_status->value === 'refunded')
                <p class="mt-4 rounded-xl bg-slate-500/10 border border-slate-500/20 px-4 py-3 text-sm text-slate-300">
                    @if($this->paymentWasCreditedToWallet())
                        Payment received after this booking's slot was released — the amount was credited to your wallet.
                    @else
                        Refunded
                    @endif
                </p>
            @endif

            @if($isActive && ($booking->payment_status->value === 'pending' || $booking->payment_status->value === 'failed'))
                <div class="mt-4 rounded-xl border border-indigo-500/20 bg-indigo-500/10 px-4 py-3">
                    <p class="text-sm text-indigo-200">Payment is {{ $booking->payment_status->label() }}. Complete payment to confirm this booking.</p>

                    <x-ui.button type="button" class="mt-3" size="sm" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment">
                        <span wire:loading.remove wire:target="initiatePayment">Pay now</span>
                        <span wire:loading wire:target="initiatePayment">Preparing payment...</span>
                    </x-ui.button>

                    <p class="mt-2 text-[11px] text-slate-500">Pay with wallet balance — coming soon.</p>

                    @if(($paymentOrder['provider'] ?? null) === 'fake' && app()->environment(['local', 'testing']))
                        <div class="mt-3 rounded-lg border border-amber-300/20 bg-amber-400/10 p-3">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-amber-200">Test mode — fake provider</p>
                            <div class="mt-2 flex gap-2">
                                <x-ui.button type="button" size="sm" wire:click="simulateFakePayment(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="simulateFakePayment(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if($isActive)
                <div class="mt-6 flex flex-wrap gap-3 border-t border-white/[0.07] pt-5">
                    <x-ui.button type="button" wire:click="openReschedulePanel" size="sm">Reschedule</x-ui.button>
                    <x-ui.button type="button" variant="danger" wire:click="openCancelPanel" size="sm">Cancel booking</x-ui.button>
                </div>

                @if($reschedulePanelOpen)
                    <section class="mt-4 rounded-2xl bg-white/[0.04] p-4" aria-label="Reschedule booking">
                        <label for="reschedule-date" class="block text-sm font-semibold text-slate-200">New date</label>
                        <input
                            id="reschedule-date"
                            type="date"
                            wire:model.live="rescheduleDate"
                            min="{{ now()->addDay()->toDateString() }}"
                            class="mt-1.5 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-sm text-white shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20"
                        >

                        <div wire:loading wire:target="rescheduleDate" class="mt-3 text-sm text-slate-400">Loading times...</div>

                        @if(!empty($rescheduleSlots))
                            <div wire:loading.remove wire:target="rescheduleDate" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4" role="group" aria-label="Choose a new time">
                                @foreach($rescheduleSlots as $slot)
                                    <button
                                        type="button"
                                        wire:click="selectRescheduleSlot('{{ $slot['starts_at'] }}')"
                                        aria-pressed="{{ $rescheduleSlotStartsAt === $slot['starts_at'] ? 'true' : 'false' }}"
                                        class="rounded-xl border-2 p-2 text-sm font-semibold transition {{ $rescheduleSlotStartsAt === $slot['starts_at'] ? 'border-indigo-500 bg-indigo-500/10 text-indigo-200' : 'border-white/10 bg-white/5 text-slate-200 hover:border-indigo-400/40' }}"
                                    >{{ \Carbon\CarbonImmutable::parse($slot['starts_at'])->timezone($booking->timezone)->format('g:i A') }}</button>
                                @endforeach
                            </div>
                        @elseif($rescheduleDate)
                            <p wire:loading.remove wire:target="rescheduleDate" class="mt-3 text-sm text-slate-400">No open times on that date &mdash; try another.</p>
                        @endif

                        <x-ui.button type="button" wire:click="confirmReschedule" :disabled="!$rescheduleSlotStartsAt" class="mt-4" size="sm">Confirm new time</x-ui.button>
                    </section>
                @endif

                @if($cancelPanelOpen)
                    <section class="mt-4 rounded-2xl bg-red-500/[0.06] p-4" aria-label="Cancel booking">
                        <label for="cancel-reason" class="block text-sm font-semibold text-slate-200">Reason (optional)</label>
                        <textarea id="cancel-reason" rows="2" wire:model="cancelReason" maxlength="500"
                                  class="mt-1.5 block w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-sm text-white shadow-sm focus:border-red-400 focus:outline-none focus:ring-4 focus:ring-red-400/20"></textarea>
                        <x-ui.button type="button" variant="danger" wire:click="confirmCancel" class="mt-3" size="sm">Yes, cancel this booking</x-ui.button>
                    </section>
                @endif
            @endif
        @endif
    </x-ui.modal>
</div>

@script
@include('livewire.frontend.booking.partials.razorpay-checkout-script')
@endscript
