@if($currentPhase === 'confirmed' && $result && ($result['recurring'] ?? false))
@php
// One checkout for every reserved class is on offer. When it is,
// it must be the ONLY primary action on the screen — a second,
// larger "pay" button pointing at the per-class path made
// students (and the client) read the per-class route as the
// default and the reduced gateway amount as a discount.
$payAllShown = $result['requires_payment'] && ($seriesPrepayment['count'] ?? 0) > 1;
@endphp
<div class="mx-auto max-w-2xl text-center">
    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full {{ $result['requires_payment'] ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' }}">
        @if($result['requires_payment'])
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        @else
        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
        @endif
    </span>
    <h2 data-booking-step-title tabindex="-1" class="mt-4 text-2xl font-black tracking-tight text-fg-strong outline-none">
        @if($result['requires_payment'])
        {{ count($result['bookings']) }} {{ \Illuminate\Support\Str::plural('class', count($result['bookings'])) }} reserved pending payment
        @else
        {{ count($result['bookings']) }} {{ \Illuminate\Support\Str::plural('class', count($result['bookings'])) }} confirmed
        @endif
    </h2>
    <p class="mt-2 text-sm leading-6 text-fg-muted">
        @if($result['requires_payment'])
        {{ $payAllShown
                    ? 'One payment below confirms all of them. Your wallet balance is applied first.'
                    : 'Complete payment from My Bookings to confirm it.' }}
        @else
        We have sent a confirmation to {{ auth()->user()?->email }}.
        @endif
    </p>

    {{--
            One checkout for every reserved class. Each class still keeps
            its own price, reservation and refund rules — this only
            changes how many times the student is asked for money.
        --}}
    @if($payAllShown)
    <div class="mt-5 rounded-2xl border-2 border-indigo-500/40 bg-indigo-500/5 p-4 text-left sm:p-5" data-series-prepayment>
        <p class="text-base font-black text-fg-strong">Pay for all {{ $seriesPrepayment['count'] }} classes</p>

        {{--
                    The arithmetic, itemised. A gateway amount smaller than
                    the total is the wallet balance doing its job, and the
                    student has to be able to SEE that — a trailing sentence
                    in muted text was read as a 50% discount.
                --}}
        <dl class="mt-3 space-y-1.5 text-sm">
            <div class="flex items-baseline justify-between gap-4">
                <dt class="text-fg-muted">Total for {{ $seriesPrepayment['count'] }} classes</dt>
                <dd class="font-semibold text-fg-strong">{{ $seriesPrepayment['total_formatted'] }}</dd>
            </div>
            @if($seriesPrepayment['uses_balance'] ?? false)
            <div class="flex items-baseline justify-between gap-4">
                <dt class="text-fg-muted">Paid from your wallet balance</dt>
                <dd class="font-semibold text-emerald-700 dark:text-emerald-300">&minus; {{ $seriesPrepayment['balance_applied_formatted'] }}</dd>
            </div>
            @endif
            <div class="flex items-baseline justify-between gap-4 border-t border-indigo-500/20 pt-2">
                <dt class="font-bold text-fg-strong">To pay now</dt>
                <dd class="text-xl font-black text-fg-strong">{{ $seriesPrepayment['shortfall_formatted'] }}</dd>
            </div>
        </dl>

        <p class="mt-2.5 text-sm leading-6 text-fg-muted">
            @if($seriesPrepayment['covered_by_wallet'])
            Your wallet balance covers all {{ $seriesPrepayment['count'] }} classes. Nothing to pay now.
            @elseif($seriesPrepayment['uses_balance'] ?? false)
            The full {{ $seriesPrepayment['total_formatted'] }} is recorded against these classes — {{ $seriesPrepayment['balance_applied_formatted'] }} from your balance and {{ $seriesPrepayment['shortfall_formatted'] }} paid now.
            @else
            This one payment confirms every class below.
            @endif
        </p>

        @if(($seriesPrepayment['planned_count'] ?? 0) > 0)
        <p class="mt-1.5 text-xs leading-5 text-fg-faint">
            This covers the {{ $seriesPrepayment['count'] }} classes reserved so far. The {{ $seriesPrepayment['planned_count'] }} still planned are booked closer to the time and paid for then.
        </p>
        @endif

        @if($paymentBanner !== '')
        <div class="booking-payment-error mt-3 rounded-xl border px-4 py-3" role="alert">
            <p class="text-sm leading-6">{{ $paymentBanner }}</p>
        </div>
        @endif

        @if($pendingSeriesPaymentId !== null && ($seriesCheckout['provider'] ?? null) !== 'stripe')
        {{--
            Money may already have moved: never offer a second Pay button
            while the gateway is being asked. The poll settles the classes
            the moment the credit lands.
        --}}
        <div class="mt-3.5 flex items-start gap-3 rounded-xl border border-indigo-300/50 bg-indigo-500/5 px-4 py-3" role="status" aria-live="polite" wire:poll.3s="pollSeriesPaymentStatus" data-series-payment-confirming>
            <x-ui.spinner size="sm" class="mt-0.5 shrink-0 text-indigo-600 dark:text-indigo-300" />
            <div>
                <p class="text-sm font-bold text-fg-strong">Confirming your payment</p>
                <p class="mt-0.5 text-sm leading-6 text-fg-muted">We are checking with the payment gateway. Your classes are confirmed as soon as it answers — there is no need to pay again.</p>
            </div>
        </div>
        @elseif(($seriesCheckout['provider'] ?? null) === 'stripe')
        {{-- wire:ignore: Stripe's Payment Element iframe must survive the polling re-renders. --}}
        <div class="mt-3.5" wire:ignore>
            <div id="series-stripe-payment-element" class="rounded-xl border border-edge bg-surface-raised p-3"></div>
            <p id="series-stripe-payment-errors" class="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300" role="alert"></p>
            <x-ui.button type="button" id="series-stripe-confirm-button" class="mt-3 w-full" disabled>
                Confirm card payment
            </x-ui.button>
        </div>
        @else
        <div class="mt-3.5 flex flex-wrap items-center gap-3">
            <x-ui.button type="button" wire:click="payForAllClasses" wire:loading.attr="disabled" wire:target="payForAllClasses">
                <span wire:loading.remove wire:target="payForAllClasses">
                    {{ $seriesPrepayment['covered_by_wallet'] ? 'Confirm all '.$seriesPrepayment['count'].' classes from balance' : 'Pay '.$seriesPrepayment['shortfall_formatted'].' now' }}
                </span>
                <span wire:loading wire:target="payForAllClasses" class="inline-flex items-center gap-2">
                    <x-ui.spinner size="sm" /> Working…
                </span>
            </x-ui.button>
            <a href="{{ $result['my_bookings_url'] }}" class="text-xs font-semibold text-fg-muted underline decoration-edge underline-offset-2 hover:text-fg-strong">
                Prefer to pay per class? Do it from My Bookings.
            </a>
        </div>
        @endif

        @if(($seriesCheckout['provider'] ?? null) === 'fake' && app()->environment(['local', 'testing']))
        <div class="mt-4 rounded-xl border border-dashed border-amber-300 bg-amber-500/10 p-3" data-series-fake-controls>
            <p class="text-[11px] font-black uppercase tracking-wide text-amber-700">Developer test controls · Fake provider</p>
            <p class="mt-1 text-xs text-amber-800/80">Visible only in local and testing environments.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button type="button" size="sm" wire:click="simulateFakeSeriesPayment(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                <x-ui.button type="button" size="sm" variant="secondary" wire:click="simulateFakeSeriesPayment(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
            </div>
        </div>
        @endif

        {{--
                    Standing permission for money to move while the student
                    is not here. Opt-in, off by default, never pre-ticked,
                    and worded as what it actually does rather than as a
                    convenience.
                --}}
        @if($autoSettleAvailable && ($seriesPrepayment['planned_count'] ?? 0) > 0)
        <label class="mt-4 flex cursor-pointer items-start gap-3 border-t border-edge pt-3.5">
            <input
                type="checkbox"
                wire:click="toggleAutoSettle"
                @checked($autoSettleEnabled)
                class="mt-0.5 h-5 w-5 shrink-0 rounded border-2 border-edge-strong text-indigo-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">
            <span class="text-sm leading-6 text-fg">
                <span class="font-bold text-fg-strong">Use my balance to confirm future classes automatically</span>
                <span class="mt-0.5 block text-xs leading-5 text-fg-muted">
                    As the {{ $seriesPrepayment['planned_count'] }} planned {{ \Illuminate\Support\Str::plural('class', $seriesPrepayment['planned_count']) }} {{ $seriesPrepayment['planned_count'] === 1 ? 'is' : 'are' }} booked, we will confirm {{ $seriesPrepayment['planned_count'] === 1 ? 'it' : 'them' }} from your balance if it covers the cost. We never charge your card for this, and you can turn it off any time from My Bookings.
                </span>
            </span>
        </label>
        @endif
    </div>
    @elseif($result['requires_payment'] && ($seriesPrepayment['blocked'] ?? null))
    <p class="mt-4 rounded-2xl border border-amber-300/40 bg-amber-500/10 px-4 py-3 text-left text-sm text-amber-900 dark:text-amber-200" role="status">
        {{ $seriesPrepayment['blocked'] }}
    </p>
    @endif

    {{--
            The confirmation horizon, said plainly. The student has just
            booked a schedule that may run far past what we have actually
            reserved, and they need to know that the rest is real and
            planned — not forgotten, and not being charged for yet.
        --}}
    @if(($result['ongoing'] ?? false))
    <p class="mt-3 rounded-2xl bg-indigo-500/10 px-4 py-3 text-sm leading-6 text-indigo-900 dark:text-indigo-200">
        This schedule keeps going until you cancel it. We book your classes a stretch at a time and add the next ones automatically, so you are only ever charged for classes that have been booked.
    </p>
    @elseif(($result['planned_count'] ?? 0) > 0)
    <p class="mt-3 rounded-2xl bg-indigo-500/10 px-4 py-3 text-sm leading-6 text-indigo-900 dark:text-indigo-200">
        {{ $result['planned_count'] }} more {{ \Illuminate\Support\Str::plural('class', $result['planned_count']) }} in this schedule {{ $result['planned_count'] === 1 ? 'is' : 'are' }} planned. We book them automatically as their dates come closer, and you are only charged for classes that have been booked.
    </p>
    @endif

    <dl class="mt-6 divide-y divide-edge rounded-2xl border border-edge bg-surface text-left text-sm">
        @foreach($result['bookings'] as $occurrence)
        <div class="flex items-center justify-between gap-4 px-4 py-3">
            <dt class="text-fg-muted">{{ viewer_datetime($occurrence['starts_at']) }}</dt>
            <dd class="font-semibold text-fg-strong">{{ $occurrence['payment_status'] === 'paid' ? 'Paid' : ($occurrence['requires_payment'] ? 'Payment due' : $occurrence['status_label']) }}</dd>
        </div>
        @endforeach
    </dl>

    @if(! empty($result['failures']))
    <div class="mt-4 rounded-2xl border border-amber-300/30 bg-amber-500/10 p-4 text-left text-xs text-amber-800 dark:text-amber-200" role="status">
        <p class="font-bold">Some dates could not be booked</p>
        <ul class="mt-2 list-disc space-y-1 pl-4">
            @foreach($result['failures'] as $when => $reason)
            <li>{{ viewer_datetime($when) }} — {{ $reason }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="mt-6 flex flex-col items-center gap-3">
        @if($payAllShown)
        {{-- The pay-all button above is the one primary action; this is plain navigation. --}}
        <x-ui.button href="{{ $result['my_bookings_url'] }}" variant="secondary" size="lg" class="w-full sm:w-auto">View my bookings</x-ui.button>
        @else
        <x-ui.button href="{{ $result['my_bookings_url'] }}" size="lg" class="w-full sm:w-auto">{{ $result['requires_payment'] ? 'Pay from My Bookings' : 'View my bookings' }}</x-ui.button>
        @endif
        <button type="button" wire:click="restart" class="min-h-10 rounded px-2 text-sm font-semibold text-fg-muted underline-offset-2 hover:text-fg-strong hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">Book another session</button>
    </div>
</div>
@endif

@if($currentPhase === 'confirmed' && $result && ! ($result['recurring'] ?? false))
@php
$isPaid = $result['payment_status'] === 'paid';
$isExpired = ! $isPaid && $result['status'] === 'cancelled';
$isAwaitingPayment = $result['requires_payment'] && ! $isPaid && ! $isExpired;
$paymentFailed = $isAwaitingPayment && $result['payment_status'] === 'failed';
$startsAt = \Carbon\CarbonImmutable::parse($result['starts_at'])->timezone($result['timezone']);
$endsAt = \Carbon\CarbonImmutable::parse($result['ends_at'])->timezone($result['timezone']);
$reservedUntil = ($result['reserved_until'] ?? null) ? \Carbon\CarbonImmutable::parse($result['reserved_until'])->timezone($result['timezone']) : null;
$contextLine = implode(' • ', array_filter([
$result['level_display'] ?? (($result['grade'] ?? null) ? 'Grade '.$result['grade'] : null),
$result['education_system_name'] ?? null,
]));
$stripeMounted = ($paymentOrder['provider'] ?? null) === 'stripe';
@endphp

<div class="mx-auto max-w-4xl">
    {{-- Status header --}}
    <div class="text-center">
        @if($isExpired)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-hover px-3 py-1 text-xs font-bold text-fg-muted">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Reservation expired
        </span>
        <h2 data-booking-step-title tabindex="-1" class="mt-3 text-2xl font-black tracking-tight text-fg-strong outline-none sm:text-3xl">This reservation has expired</h2>
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">The time you chose was released because payment was not completed in time. Choose another available time to book again.</p>
        @elseif($isAwaitingPayment)
        <span
            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
            @if($reservedUntil)
            x-data="{
                            expiresAt: {{ $reservedUntil->getTimestampMs() }},
                            now: Date.now(),
                            ended: false,
                            init() {
                                const tick = () => {
                                    this.now = Date.now();
                                    if (this.remaining <= 0 && ! this.ended) {
                                        this.ended = true;
                                        clearInterval(timer);
                                        $wire.checkPaymentStatus();
                                    }
                                };
                                const timer = setInterval(tick, 1000);
                                tick();
                            },
                            get remaining() { return Math.max(0, Math.floor((this.expiresAt - this.now) / 1000)); },
                            get label() { return Math.floor(this.remaining / 60) + ':' + String(this.remaining % 60).padStart(2, '0'); }
                        }"
            @endif
            role="status">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            @if($reservedUntil)
            <span x-show="! ended">Reserved for <span x-text="label">{{ max(0, (int) ceil(now()->diffInMinutes($reservedUntil, false))) }} min</span></span>
            <span x-show="ended" x-cloak>Reservation time has ended</span>
            @else
            Reserved temporarily
            @endif
        </span>
        @if($awaitingPaymentConfirmation)
        @php
        $checkoutState = \App\Booking\Enums\BookingCheckoutState::tryFrom((string) $paymentConfirmationState) ?? \App\Booking\Enums\BookingCheckoutState::AwaitingCapture;
        @endphp
        <h2 data-booking-step-title tabindex="-1" class="mt-3 text-2xl font-black tracking-tight text-fg-strong outline-none sm:text-3xl">{{ $checkoutState->title() }}</h2>
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">
            {{ $checkoutState->message() }} Your lesson time stays reserved.
        </p>
        @else
        <h2 data-booking-step-title tabindex="-1" class="mt-3 text-2xl font-black tracking-tight text-fg-strong outline-none sm:text-3xl">Complete your payment</h2>
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">
            Your lesson time is reserved while you complete payment.
            @if($reservedUntil)
            Reserved until {{ $reservedUntil->format('g:i A') }}.
            @endif
        </p>
        @endif
        @else
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            Confirmed
        </span>
        <h2 data-booking-step-title tabindex="-1" class="mt-3 text-2xl font-black tracking-tight text-fg-strong outline-none sm:text-3xl">Booking confirmed</h2>
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">We have sent a confirmation to {{ auth()->user()?->email }}.</p>
        @endif
    </div>

    <div class="mt-6 grid gap-4 {{ $isAwaitingPayment ? 'md:grid-cols-2' : 'mx-auto max-w-lg' }}">
        {{-- Lesson summary --}}
        <section class="rounded-2xl border border-edge bg-surface p-5 md:self-start" aria-labelledby="checkout-lesson">
            <h3 id="checkout-lesson" class="text-[11px] font-black uppercase tracking-[0.14em] text-fg-muted">Your lesson</h3>
            <p class="mt-2 text-xl font-black leading-6 text-fg-strong">{{ $result['subject'] ?? $result['type']['name'] }}</p>
            @if($contextLine)
            <p class="mt-0.5 text-sm text-fg-muted">{{ $contextLine }}</p>
            @endif

            <dl class="mt-4 space-y-2.5 border-t border-edge pt-4 text-sm">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                    <div>
                        <dt class="sr-only">When</dt>
                        <dd class="font-semibold text-fg-strong">{{ $startsAt->format('l, j F') }}</dd>
                        <dd class="text-fg-muted">{{ $startsAt->format('g:i A') }} – {{ $endsAt->format('g:i A') }} · {{ $result['timezone'] }}</dd>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.55 50.55 0 0112 13.489a50.55 50.55 0 017.74-3.342" />
                    </svg>
                    <div>
                        <dt class="sr-only">Session</dt>
                        <dd class="font-semibold text-fg-strong">{{ $result['type']['name'] }}</dd>
                    </div>
                </div>
                @if($lockedInstructorName)
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                    <div>
                        <dt class="sr-only">Instructor</dt>
                        <dd class="font-semibold text-fg-strong">{{ $lockedInstructorName }}</dd>
                    </div>
                </div>
                @endif
            </dl>

            <p class="mt-4 border-t border-edge pt-3 text-xs text-fg-muted">
                Reference <span class="font-mono font-semibold text-fg">{{ $result['reference'] }}</span>
            </p>
        </section>

        {{-- Payment --}}
        @if($isAwaitingPayment)
        <section class="rounded-2xl border border-indigo-300/50 bg-surface-raised p-5 shadow-sm shadow-indigo-500/10 ring-1 ring-indigo-500/10" aria-labelledby="checkout-payment">
            <h3 id="checkout-payment" class="text-[11px] font-black uppercase tracking-[0.14em] text-indigo-700 dark:text-indigo-300">Payment</h3>

            <p class="mt-2 text-sm text-fg-muted">Total due</p>
            <p class="text-3xl font-black tracking-tight text-fg-strong">{{ $result['amount_formatted'] ?? '—' }}</p>

            @if($awaitingPaymentConfirmation)
            {{-- Verified checkout, settlement pending. The state is derived on
                             the server (BookingCheckoutState): waiting for capture, provider
                             unreachable, or needs attention. Poll — the callback re-check,
                             the webhook or the reconciliation sweep settles it — and never
                             offer a second Pay button for money that may already have moved. --}}
            @php
            $checkoutState = \App\Booking\Enums\BookingCheckoutState::tryFrom((string) $paymentConfirmationState) ?? \App\Booking\Enums\BookingCheckoutState::AwaitingCapture;
            @endphp
            <div
                class="booking-payment-confirming mt-4 rounded-xl border px-4 py-4 {{ $checkoutState === \App\Booking\Enums\BookingCheckoutState::AwaitingCapture ? 'border-indigo-300/50 bg-indigo-500/5' : 'border-amber-300/60 bg-amber-400/10' }}"
                data-checkout-state="{{ $checkoutState->value }}"
                role="status"
                aria-live="polite"
                wire:poll.3s="checkPaymentStatus"
                x-data="{ since: {{ \Carbon\CarbonImmutable::parse($awaitingPaymentSince ?? now())->getTimestampMs() }}, now: Date.now(), init() { setInterval(() => this.now = Date.now(), 1000); }, get slow() { return this.now - this.since > 60000; } }">
                <div class="flex items-start gap-3">
                    <x-ui.spinner size="sm" class="mt-0.5 shrink-0 {{ $checkoutState === \App\Booking\Enums\BookingCheckoutState::AwaitingCapture ? 'text-indigo-600 dark:text-indigo-300' : 'text-amber-600 dark:text-amber-300' }}" />
                    <div>
                        <p class="text-sm font-bold text-fg-strong">{{ $checkoutState->title() }}</p>
                        <p class="mt-0.5 text-sm leading-6 text-fg-muted">{{ $checkoutState->message() }}</p>
                        @if($checkoutState->delayedMessage())
                        <p x-show="slow" x-cloak class="mt-2 text-sm leading-6 text-fg-muted">
                            {{ $checkoutState->delayedMessage() }} Your time stays reserved until the hold ends.
                        </p>
                        @endif
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="checkPaymentStatus" wire:loading.attr="disabled" wire:target="checkPaymentStatus">
                        <span wire:loading.remove wire:target="checkPaymentStatus">Check again</span>
                        <span wire:loading wire:target="checkPaymentStatus">Checking…</span>
                    </x-ui.button>
                    <a href="{{ route('dashboard.bookings.index') }}" class="inline-flex items-center text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300">Back to my bookings</a>
                </div>
            </div>
            @else
            @if($paymentBanner || $paymentFailed)
            <div class="booking-payment-error mt-4 rounded-xl border px-4 py-3" role="alert">
                <p class="text-sm font-bold">{{ $paymentFailed ? 'Payment wasn’t completed.' : 'Payment needs attention' }}</p>
                <p class="mt-0.5 text-sm leading-6">{{ $paymentBanner !== '' ? $paymentBanner : 'Your booking has not been confirmed. You can try again below.' }}</p>
            </div>
            @endif

            <x-ui.button type="button" size="lg" class="booking-checkout-primary mt-4 w-full" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment" aria-describedby="checkout-secure-note">
                <span wire:loading.remove wire:target="initiatePayment" class="inline-flex items-center gap-2">
                    {{ $paymentFailed ? 'Try payment again' : (($result['amount_formatted'] ?? null) ? 'Pay '.$result['amount_formatted'].' securely' : 'Pay now') }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </span>
                <span wire:loading wire:target="initiatePayment" class="inline-flex items-center gap-2" role="status">
                    <x-ui.spinner size="sm" class="text-white" />
                    Preparing secure payment…
                </span>
            </x-ui.button>
            <p id="checkout-secure-note" class="mt-2 flex items-start gap-1.5 text-xs leading-5 text-fg-muted">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span>Secure payment. Your booking is confirmed after the payment succeeds.</span>
            </p>
            @endif

            @if($stripeMounted)
            {{-- wire:ignore: this subtree is polled by checkPaymentStatus() every
                             few seconds while confirming — Livewire must never re-morph it, or
                             the mounted Stripe Elements iframe (DOM Livewire doesn't know about)
                             would be torn down mid-confirmation. --}}
            <div class="mt-4" wire:ignore>
                <div id="stripe-payment-element" class="rounded-xl border border-edge bg-surface-raised p-3"></div>
                <p id="stripe-payment-errors" class="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300" role="alert"></p>
                <x-ui.button type="button" id="stripe-confirm-button" size="lg" class="booking-checkout-primary mt-3 w-full" disabled>
                    Confirm card payment
                </x-ui.button>
            </div>
            @endif

            @if($walletOption['available'] ?? false)
            <div class="mt-5 border-t border-edge pt-4" aria-labelledby="checkout-wallet">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p id="checkout-wallet" class="text-sm font-bold text-fg-strong">Wallet</p>
                        <p class="text-xs text-fg-muted">Balance <span class="font-semibold text-fg">{{ $walletOption['balance_formatted'] }}</span></p>
                    </div>
                    @unless($walletOption['sufficient'] ?? false)
                    <span class="rounded-full bg-surface-hover px-2.5 py-1 text-[11px] font-bold text-fg-muted">Insufficient balance</span>
                    @endunless
                </div>
                @if($walletOption['sufficient'] ?? false)
                <x-ui.button type="button" variant="secondary" class="booking-checkout-secondary mt-3 w-full" wire:click="payWithWallet" wire:loading.attr="disabled" wire:target="payWithWallet">
                    <span wire:loading.remove wire:target="payWithWallet">Pay {{ $result['amount_formatted'] }} from wallet</span>
                    <span wire:loading wire:target="payWithWallet" role="status">Paying from wallet…</span>
                </x-ui.button>
                @else
                <p class="mt-2 text-xs text-fg-muted">Your wallet balance does not cover this booking, so wallet payment is not available for it.</p>
                @endif
            </div>
            @endif

            @if(($paymentOrder['provider'] ?? null) === 'fake' && app()->environment(['local', 'testing']))
            <div class="mt-4 rounded-xl border border-dashed border-amber-300 bg-amber-500/10 p-3">
                <p class="text-[11px] font-black uppercase tracking-wide text-amber-700">Developer test controls · Fake provider</p>
                <p class="mt-1 text-xs text-amber-800/80">Visible only in local and testing environments.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button type="button" size="sm" class="booking-checkout-test-success" wire:click="simulateFakePayment(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                    <x-ui.button type="button" size="sm" variant="secondary" class="booking-checkout-test-failure" wire:click="simulateFakePayment(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
                </div>
            </div>
            @endif
        </section>
        @endif
    </div>

    {{-- Secondary navigation --}}
    <div class="mt-6 flex flex-col items-center gap-3 text-center">
        @if($isExpired)
        <x-ui.button type="button" size="lg" wire:click="restart" class="w-full sm:w-auto">Choose another time</x-ui.button>
        <a href="{{ $result['my_bookings_url'] }}" class="min-h-10 rounded px-2 text-sm font-semibold text-fg-muted underline-offset-2 hover:text-fg-strong hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">Back to my bookings</a>
        @elseif($isAwaitingPayment)
        <a href="{{ $result['my_bookings_url'] }}" class="inline-flex min-h-10 items-center gap-1.5 rounded px-2 text-sm font-semibold text-fg-muted underline-offset-2 hover:text-fg-strong hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            Back to my bookings
        </a>
        <p class="text-xs text-fg-faint">You can also pay later from My Bookings while the reservation is held.</p>
        @else
        <div class="booking-checkout-actions flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
            <x-ui.button href="{{ $result['my_bookings_url'] }}" size="lg" class="booking-checkout-primary">View my bookings</x-ui.button>
            <x-ui.button type="button" variant="secondary" size="lg" class="booking-checkout-secondary" wire:click="restart">Book another session</x-ui.button>
        </div>
        @endif
    </div>
</div>

@if($isAwaitingPayment && ! $stripeMounted)
<div class="booking-mobile-footer fixed inset-x-0 bottom-0 z-40 border-t border-edge bg-surface-raised/95 px-4 py-3 backdrop-blur md:hidden" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
    <div class="mx-auto flex max-w-3xl items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">{{ $awaitingPaymentConfirmation ? 'Payment' : 'Total due' }}</p>
            <p class="text-lg font-black leading-6 text-fg-strong">{{ $awaitingPaymentConfirmation ? 'Confirming…' : ($result['amount_formatted'] ?? '—') }}</p>
        </div>
        @if($awaitingPaymentConfirmation)
        {{-- Money has been accepted: no second Pay button, on any screen size. --}}
        <span class="inline-flex shrink-0 items-center gap-2 text-sm font-semibold text-indigo-700 dark:text-indigo-300" role="status">
            <x-ui.spinner size="sm" class="text-indigo-600 dark:text-indigo-300" />
            Confirming payment
        </span>
        @else
        <x-ui.button type="button" class="booking-checkout-primary shrink-0" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment" aria-label="Pay {{ $result['amount_formatted'] ?? '' }} securely">
            <span wire:loading.remove wire:target="initiatePayment">Pay securely</span>
            <span wire:loading wire:target="initiatePayment">Preparing…</span>
        </x-ui.button>
        @endif
    </div>
</div>
@endif
@endif