<div>
    @if($booking)
        @php
            $isActive = ! $booking->status->isTerminal();
            $rescheduleAllowance = $this->rescheduleAllowance();
        @endphp

        @if($banner)
            <x-ui.alert type="error" class="mb-4">{{ $banner }}</x-ui.alert>
        @endif

        @php
            $viewerTz = \App\Support\Timezone\ViewerDateTime::timezoneFor();
            $joinLive = $isActive && $booking->status->value === 'confirmed';
            $joinOpen = $joinLive && $joinState->isAvailable();
        @endphp

        {{-- Summary: which session, when (once, as a full range in the viewer's
             timezone), what state it is in, and the single join action. --}}
        <x-account.card class="mb-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-bold text-fg-strong">{{ $booking->type?->name ?? 'Session' }}</h2>
                        @if($awaitingCompletion)
                            {{-- Ended on the clock, outcome not yet finalized: the
                                 booking is still Confirmed underneath, but "Confirmed"
                                 reads as upcoming. Polled until it flips to Completed. --}}
                            <x-ui.badge color="slate" data-booking-state="completion-pending">Lesson ended · Completion pending</x-ui.badge>
                        @else
                            <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                        @endif
                        @if($booking->payment_status !== \App\Booking\Enums\BookingPaymentStatus::NotRequired)
                            <x-ui.badge :color="$booking->payment_status->color()">{{ $booking->payment_status->label() }}</x-ui.badge>
                        @endif
                    </div>
                    <p class="mt-1.5 text-sm font-semibold text-fg-strong">
                        {{ viewer_datetime($booking->starts_at, 'D, j M Y') }} · {{ viewer_time($booking->starts_at) }}–{{ viewer_time($booking->ends_at) }}
                        <span class="font-normal text-fg-muted">({{ $viewerTz }})</span>
                    </p>
                    <p class="mt-1 text-xs text-fg-faint">Reference {{ $booking->reference }}</p>
                </div>

                @if($booking->price !== null && $booking->payment_status !== \App\Booking\Enums\BookingPaymentStatus::NotRequired)
                    <div class="text-right">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Amount</p>
                        <p class="text-lg font-bold text-fg-strong">{{ $booking->currency }} {{ number_format((float) $booking->price, 2) }}</p>
                    </div>
                @endif
            </div>

            {{-- Join state. $joinState comes exclusively from
                 BookingMeetingService::studentJoinStateFor(); this blade never
                 reads meeting->join_url. Polled while the window can still
                 change on its own. The shared component emits data-join-state. --}}
            @if($joinLive)
                <div class="mt-4 border-t border-edge pt-4" @if($pollJoinState) wire:poll.60s @endif>
                    @if($awaitingCompletion && ! $joinOpen)
                        <p class="mb-2 text-sm text-fg-muted">The lesson is being marked complete. This usually takes about 15–20 minutes after the scheduled end.</p>
                    @endif
                    <x-student.join-action :state="$joinState" :booking="$booking" :show-passcode="true" />
                </div>
            @endif
        </x-account.card>

        <x-account.card title="Session details">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                @if($booking->timezone && $booking->timezone !== $viewerTz)
                    {{-- TZ-4: provenance. The header shows the viewer's clock;
                         this records the timezone the booking was made in. --}}
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Booked in</dt>
                        <dd class="mt-1 font-semibold text-fg-strong">{{ $booking->timezone }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Instructor</dt>
                    <dd class="mt-1 font-semibold">
                        @if($booking->instructor)
                            <a href="{{ route('instructors.show', $booking->instructor) }}" target="_blank" rel="noopener" class="text-indigo-600 underline underline-offset-2 hover:text-indigo-700 dark:text-indigo-300 hover:dark:text-indigo-200">{{ $booking->instructor->name }}</a>
                        @else
                            <span class="text-fg-strong">Teacher</span>
                        @endif
                    </dd>
                </div>
                @if(($booking->meta['subject'] ?? null) !== null)
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Subject</dt>
                        <dd class="mt-1 font-semibold capitalize text-fg-strong">
                            {{ str_replace(['_', '-'], ' ', $booking->meta['subject']) }}
                            {{-- Phase 3.1: a country-aware academic booking carries its own
                                 immutable snapshot (e.g. "Class 10") — prefer it over the
                                 legacy "Grade {n}" fallback, and never reconstruct it from
                                 current EducationSystem config (the snapshot IS the historical
                                 record, even after an admin later renames the level). --}}
                            @if($booking->academicContext)
                                &middot; {{ $booking->academicContext->level_display }}
                            @elseif($booking->meta['grade'] ?? null)
                                &middot; Grade {{ $booking->meta['grade'] }}
                            @endif
                        </dd>
                    </div>
                @endif
                {{-- $recordingState comes exclusively from RecordingPlaybackAccessResolver::stateFor(); the recording row is used here only as the route key, never inspected. --}}
                @if($recordingState->isVisible())
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Recording</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <x-ui.badge :color="$recordingState->color()">{{ $recordingState->label() }}</x-ui.badge>
                            @if($recordingState === \App\Booking\Enums\RecordingPlaybackState::Available && $booking->recording)
                                <a href="{{ route('dashboard.recordings.watch', $booking->recording) }}" class="font-semibold text-indigo-600 underline underline-offset-2 dark:text-indigo-300">Watch recording</a>
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-fg-muted">{{ $recordingState->description() }}</dd>
                    </div>
                @elseif($awaitingCompletion)
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-fg-faint">Recording</dt>
                        <dd class="mt-1"><x-ui.badge color="slate">Completion pending</x-ui.badge></dd>
                        <dd class="mt-1 text-xs text-fg-muted">If this lesson was recorded, the recording appears here once the lesson is marked complete.</dd>
                    </div>
                @endif
            </dl>

            @if($booking->status->value === 'cancelled')
                <p class="mt-5 rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-300">
                    Cancelled
                    @if($booking->cancellation_reason)
                        &mdash; {{ $booking->cancellation_reason }}
                    @endif
                </p>

                @if($outcome = $this->cancellationOutcomeMessage())
                    <p class="mt-2 rounded-xl bg-surface-raised px-4 py-3 text-sm text-fg-muted">{{ $outcome }}</p>
                @endif
            @endif

            @if($booking->payment_status->value === 'refunded')
                <p class="mt-5 rounded-xl border border-slate-500/20 bg-slate-500/10 px-4 py-3 text-sm text-fg-muted">
                    @if($this->paymentWasCreditedToWallet())
                        Payment received after this booking's slot was released — the amount was credited to your wallet.
                    @else
                        Refunded
                    @endif
                </p>
            @endif

            @if($isActive && $awaitingPaymentConfirmation && $booking->payment_status->value === 'pending')
                {{-- Verified checkout, settlement pending. State derived on the server
                     (BookingCheckoutState) and restored on every load: poll, never a
                     second Pay button for money that may already have moved. --}}
                @php
                    $checkoutState = \App\Booking\Enums\BookingCheckoutState::tryFrom((string) $paymentConfirmationState) ?? \App\Booking\Enums\BookingCheckoutState::AwaitingCapture;
                @endphp
                <div class="mt-5 rounded-xl border px-4 py-3 {{ $checkoutState === \App\Booking\Enums\BookingCheckoutState::AwaitingCapture ? 'border-indigo-500/20 bg-indigo-500/10' : 'border-amber-400/40 bg-amber-400/10' }}" data-checkout-state="{{ $checkoutState->value }}" role="status" aria-live="polite" wire:poll.3s="checkPaymentStatus">
                    <div class="flex items-start gap-3">
                        <x-ui.spinner size="sm" class="mt-0.5 shrink-0 {{ $checkoutState === \App\Booking\Enums\BookingCheckoutState::AwaitingCapture ? 'text-indigo-600 dark:text-indigo-300' : 'text-amber-600 dark:text-amber-300' }}" />
                        <div>
                            <p class="text-sm font-bold text-fg-strong">{{ $checkoutState->title() }}</p>
                            <p class="mt-0.5 text-sm leading-6 text-fg-muted">{{ $checkoutState->message() }}</p>
                            @if($checkoutState->delayedMessage())
                                <p class="mt-2 text-xs leading-5 text-fg-muted">{{ $checkoutState->delayedMessage() }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif($isActive && ($booking->payment_status->value === 'pending' || $booking->payment_status->value === 'failed'))
                <div class="mt-5 rounded-xl border border-indigo-500/20 bg-indigo-500/10 px-4 py-3">
                    <p class="text-sm text-indigo-700 dark:text-indigo-200">Payment is {{ $booking->payment_status->label() }}. Complete payment to confirm this booking.</p>

                    <x-ui.button type="button" class="mt-3" size="sm" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment">
                        <span wire:loading.remove wire:target="initiatePayment">Pay now</span>
                        <span wire:loading wire:target="initiatePayment">Preparing payment...</span>
                    </x-ui.button>

                    @if($this->walletOption()['available'] ?? false)
                        <div class="mt-3 rounded-xl border border-edge bg-surface-raised p-3">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">Pay with wallet</p>
                            <p class="mt-1 text-xs text-fg-muted">Wallet balance: <span class="font-semibold text-fg-strong">{{ $this->walletOption()['balance_formatted'] }}</span></p>

                            @if($this->walletOption()['sufficient'] ?? false)
                                <x-ui.button type="button" variant="ghost" size="sm" class="mt-2" wire:click="payWithWallet" wire:loading.attr="disabled" wire:target="payWithWallet">
                                    <span wire:loading.remove wire:target="payWithWallet">Pay from wallet</span>
                                    <span wire:loading wire:target="payWithWallet">Paying...</span>
                                </x-ui.button>
                            @else
                                <p class="mt-2 text-[11px] text-amber-600 dark:text-amber-300">Your wallet balance is not sufficient to pay for this booking.</p>
                            @endif
                        </div>
                    @endif

                    @if(($paymentOrder['provider'] ?? null) === 'stripe')
                        {{-- wire:ignore: this subtree is polled by checkPaymentStatus() every few
                             seconds while confirming — Livewire must never re-morph it, or the
                             mounted Stripe Elements iframe (DOM Livewire doesn't know about) would
                             be torn down mid-confirmation. --}}
                        <div class="mt-3" wire:ignore>
                            <div id="stripe-payment-element" class="rounded-lg bg-white p-3"></div>
                            <p id="stripe-payment-errors" class="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300" role="alert"></p>
                            <x-ui.button type="button" id="stripe-confirm-button" class="mt-3 w-full justify-center" disabled>
                                Confirm card payment
                            </x-ui.button>
                        </div>
                    @endif

                    @if(($paymentOrder['provider'] ?? null) === 'fake' && app()->environment(['local', 'testing']))
                        <div class="mt-3 rounded-lg border border-amber-300/20 bg-amber-400/10 p-3">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">Test mode — fake provider</p>
                            <div class="mt-2 flex gap-2">
                                <x-ui.button type="button" size="sm" wire:click="simulateFakePayment(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="simulateFakePayment(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if($isActive && $booking->hasStarted())
                <p class="mt-6 border-t border-edge pt-4 text-xs text-fg-muted" data-lesson-started-notice>
                    @if($booking->hasEnded())
                        This lesson has ended. Rescheduling and cancelling are no longer available.
                    @else
                        This lesson is in progress. Rescheduling and cancelling are no longer available.
                    @endif
                </p>
            @elseif($isActive)
                <div class="mt-6 flex flex-wrap gap-3 border-t border-edge pt-5">
                    @if($rescheduleAllowance === null || $rescheduleAllowance['allowed'])
                        <x-ui.button type="button" wire:click="openReschedulePanel" size="sm">Reschedule</x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="danger" wire:click="openCancelPanel" size="sm">Cancel booking</x-ui.button>
                </div>

                @if($rescheduleAllowance !== null && ! $rescheduleAllowance['allowed'])
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-300">You have reached the reschedule limit for this lesson.</p>
                @endif

                @if($reschedulePanelOpen)
                    <section class="mt-4 rounded-2xl border border-edge bg-surface-raised p-4" aria-label="Reschedule booking">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-fg-strong">Pick a new time</h3>
                                @if($rescheduleAllowance !== null)
                                    <p class="mt-0.5 text-xs text-fg-muted">
                                        {{ $rescheduleAllowance['remaining'] === 1 ? '1 reschedule remaining' : $rescheduleAllowance['remaining'].' reschedules remaining' }}
                                    </p>
                                @endif
                            </div>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeReschedulePanel">Close</x-ui.button>
                        </div>

                        <label for="reschedule-date" class="mt-3 block text-sm font-semibold text-fg">New date</label>
                        <input
                            id="reschedule-date"
                            type="date"
                            wire:model.live="rescheduleDate"
                            min="{{ now()->addDay()->toDateString() }}"
                            class="mt-1.5 rounded-xl border border-edge bg-surface-raised px-3.5 py-2.5 text-sm text-fg-strong shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20"
                        >

                        <div wire:loading wire:target="rescheduleDate" class="mt-3 text-sm text-fg-muted">Loading times...</div>

                        @if(!empty($rescheduleSlots))
                            <div wire:loading.remove wire:target="rescheduleDate" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4" role="group" aria-label="Choose a new time">
                                @foreach($rescheduleSlots as $slot)
                                    <button
                                        type="button"
                                        wire:click="selectRescheduleSlot('{{ $slot['starts_at'] }}')"
                                        aria-pressed="{{ $rescheduleSlotStartsAt === $slot['starts_at'] ? 'true' : 'false' }}"
                                        class="rounded-xl border-2 p-2 text-sm font-semibold transition {{ $rescheduleSlotStartsAt === $slot['starts_at'] ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-200' : 'border-edge bg-surface-raised text-fg hover:border-indigo-400/40' }}"
                                    >{{ viewer_time($slot['starts_at']) }}</button>
                                @endforeach
                            </div>
                        @elseif($rescheduleDate)
                            <p wire:loading.remove wire:target="rescheduleDate" class="mt-3 text-sm text-fg-muted">No open times on that date &mdash; try another.</p>
                        @endif

                        <x-ui.button type="button" wire:click="confirmReschedule" :disabled="!$rescheduleSlotStartsAt" class="mt-4" size="sm">Confirm new time</x-ui.button>
                    </section>
                @endif

                @if($cancelPanelOpen)
                    <section class="mt-4 rounded-2xl border border-red-500/20 bg-red-500/[0.06] p-4" aria-label="Cancel booking">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-sm font-bold text-fg-strong">Cancel this booking</h3>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeCancelPanel">Close</x-ui.button>
                        </div>

                        @if($preview = $this->cancellationRefundPreview())
                            @if($preview['eligible'])
                                <p class="mt-3 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600 dark:text-emerald-300">Eligible for a full wallet refund.</p>
                            @else
                                <p class="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-600 dark:text-amber-300">
                                    This cancellation is outside the refund window and will not be refunded.
                                    @if($preview['cutoff_at'])
                                        The refund deadline was {{ viewer_datetime_labelled($preview['cutoff_at'], 'D, M j Y \a\t H:i') }}.
                                    @endif
                                </p>
                            @endif
                            <p class="mt-2 text-xs text-fg-muted">Eligible refunds are credited to your wallet, not your original payment method.</p>
                        @endif

                        @if($series)
                            {{--
                                A class inside a repeating schedule needs
                                to be explicit about what is being
                                cancelled. The narrowest option is the
                                default so ending a whole schedule is
                                always a deliberate act.
                            --}}
                            <fieldset class="mt-4">
                                <legend class="text-sm font-semibold text-fg">What would you like to cancel?</legend>
                                <div class="mt-2 space-y-2">
                                    @foreach(\App\Booking\Enums\SeriesChangeScope::cases() as $scope)
                                        <label class="flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 {{ $cancelScope === $scope->value ? 'border-red-400 bg-red-500/10' : 'border-edge bg-surface-raised' }}">
                                            <input type="radio" name="cancel-scope" value="{{ $scope->value }}" wire:model.live="cancelScope" class="h-4 w-4 accent-red-600 focus-visible:ring-4 focus-visible:ring-red-300/50">
                                            <span class="text-sm font-semibold text-fg">{{ $scope->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-2 text-xs leading-5 text-fg-muted">Classes you have already taken, and anything you have paid for them, are never affected.</p>
                            </fieldset>
                        @endif

                        <label for="cancel-reason" class="mt-3 block text-sm font-semibold text-fg">Reason (optional)</label>
                        <textarea id="cancel-reason" rows="2" wire:model="cancelReason" maxlength="500"
                                  class="mt-1.5 block w-full rounded-xl border border-edge bg-surface-raised px-3.5 py-2.5 text-sm text-fg-strong shadow-sm focus:border-red-400 focus:outline-none focus:ring-4 focus:ring-red-400/20"></textarea>
                        <x-ui.button type="button" variant="danger" wire:click="confirmCancel" class="mt-3" size="sm">Yes, cancel this booking</x-ui.button>
                    </section>
                @endif
            @endif
        </x-account.card>
    @endif

    @if($series && $seriesSchedule)
        {{--
            The whole repeating schedule, paginated. Classes that exist
            are shown as they really are (including any that were moved),
            and dates the rule still owes are shown as planned — the two
            are never blended, because only one of them is a reservation.
        --}}
        <x-account.card class="mt-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black text-fg-strong">Repeating schedule</h2>
                    <p class="mt-1 text-sm text-fg-muted">
                        {{ $series->describe() }}
                        @if($series->end_condition === \App\Booking\Enums\RecurrenceEndCondition::Never)
                            · continues until you cancel it
                        @elseif($seriesSchedule->totalScheduled !== null)
                            · {{ $seriesSchedule->totalScheduled }} {{ \Illuminate\Support\Str::plural('class', $seriesSchedule->totalScheduled) }}
                        @endif
                    </p>
                </div>
                @if(! $series->isOngoing() && $series->status !== \App\Booking\Enums\BookingSeriesStatus::Cancelled)
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="openExtendPanel">Add more classes</x-ui.button>
                @endif
            </div>

            {{--
                Standing permission for money to move while the student is
                away. Shown whenever it is ON — even if the platform
                capability has since been switched off — because a control
                that STOPS spending must never disappear on someone who is
                inside it.
            --}}
            @if($autoSettleAvailable || $series->auto_settle_from_wallet)
                <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-xl border border-edge bg-surface px-3.5 py-3">
                    <input
                        type="checkbox"
                        wire:click="toggleSeriesAutoSettle"
                        @checked($series->auto_settle_from_wallet)
                        class="mt-0.5 h-5 w-5 shrink-0 rounded border-2 border-edge-strong text-indigo-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50"
                    >
                    <span class="text-sm leading-6 text-fg">
                        <span class="font-bold text-fg-strong">Confirm future classes from my balance</span>
                        <span class="mt-0.5 block text-xs leading-5 text-fg-muted">
                            When a new class in this schedule is booked, we confirm it from your balance if it covers the cost. Your card is never charged for this, and you can turn this off at any time.
                        </span>
                    </span>
                </label>
            @endif

            @if($extendPanelOpen)
                <section class="mt-4 rounded-2xl border border-edge bg-surface-raised p-4" aria-label="Extend this schedule">
                    @if($series->end_condition === \App\Booking\Enums\RecurrenceEndCondition::AfterCount)
                        <label for="extend-classes" class="block text-sm font-semibold text-fg">How many more classes?</label>
                        <input id="extend-classes" type="number" min="1" max="520" wire:model="extendByClasses"
                               class="mt-2 min-h-11 w-24 rounded-xl border border-edge bg-surface px-3 text-center text-base font-bold text-fg-strong focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-300/30">
                    @else
                        <label for="extend-date" class="block text-sm font-semibold text-fg">New last date</label>
                        <input id="extend-date" type="date" wire:model="extendToDate"
                               class="mt-2 min-h-11 rounded-xl border border-edge bg-surface px-3 text-base font-bold text-fg-strong focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-300/30">
                    @endif
                    <p class="mt-2 text-xs leading-5 text-fg-muted">Your existing classes are untouched — nothing is rebooked and nothing you have paid for changes.</p>
                    <div class="mt-3 flex gap-2">
                        <x-ui.button type="button" size="sm" wire:click="extendSeries">Add them</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeExtendPanel">Cancel</x-ui.button>
                    </div>
                </section>
            @endif

            <ol class="mt-4 divide-y divide-edge" aria-label="Classes in this schedule">
                @foreach($seriesSchedule->occurrences as $occurrence)
                    @php $row = $occurrence->toDisplayArray(\App\Support\Timezone\ViewerDateTime::timezoneFor()); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-fg-strong">
                                <span class="tabular-nums text-fg-muted">{{ $row['sequence'] }}.</span>
                                {{ $row['date_label'] }}@if($row['time_label']) · {{ $row['time_label'] }}@endif
                            </p>
                            <p class="mt-0.5 text-xs font-semibold {{ $row['is_conflict'] ? 'text-amber-700 dark:text-amber-300' : 'text-fg-muted' }}">
                                {{ $row['status_label'] }}@if($row['booking_reference']) · {{ $row['booking_reference'] }}@endif
                                @if($row['reason']) — {{ $row['reason'] }} @endif
                            </p>
                        </div>
                        @if($row['booking_id'] && $row['booking_id'] !== $booking->id)
                            <a href="{{ route('dashboard.my-bookings.show', $row['booking_id']) }}"
                               class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-indigo-600 hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 dark:text-indigo-300">
                                Open<span class="sr-only"> the class on {{ $row['date_label'] }}</span>
                            </a>
                        @elseif($row['booking_id'] === $booking->id)
                            <span class="rounded-full bg-indigo-500/10 px-3 py-1 text-xs font-black uppercase tracking-wide text-indigo-700 dark:text-indigo-200">This class</span>
                        @elseif($row['is_conflict'])
                            {{-- A class we could not book. The sweep will not
                                 revisit it on its own, so the student can ask. --}}
                            <button
                                type="button"
                                wire:click="retrySeriesOccurrence('{{ $row['local_date'] }}')"
                                wire:loading.attr="disabled"
                                class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-indigo-600 hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:opacity-50 dark:text-indigo-300"
                            >
                                Try again<span class="sr-only"> for the class on {{ $row['date_label'] }}</span>
                            </button>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if($seriesPage > 1 || $seriesSchedule->hasMore)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <button type="button" wire:click="seriesPreviousPage" @disabled($seriesPage <= 1)
                        class="min-h-11 rounded-xl border border-edge px-3 text-sm font-bold text-fg transition hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed disabled:opacity-40">Earlier</button>
                    <span class="text-xs font-semibold text-fg-muted" aria-live="polite">Page {{ $seriesPage }}</span>
                    <button type="button" wire:click="seriesNextPage" @disabled(! $seriesSchedule->hasMore)
                        class="min-h-11 rounded-xl border border-edge px-3 text-sm font-bold text-fg transition hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed disabled:opacity-40">Later</button>
                </div>
            @endif

            @if($seriesSchedule->plannedCount > 0 || $series->isOngoing())
                <p class="mt-4 text-xs leading-5 text-fg-muted">
                    We book your classes about {{ $seriesSchedule->horizonDays }} days ahead. Dates marked <em>Planned</em> are part of your schedule and are booked automatically as they come closer — you are only charged for classes that have been booked.
                </p>
            @endif
        </x-account.card>
    @endif
</div>

@script
@include('livewire.frontend.booking.partials.razorpay-checkout-script')
@include('livewire.frontend.booking.partials.stripe-checkout-script')
@endscript
