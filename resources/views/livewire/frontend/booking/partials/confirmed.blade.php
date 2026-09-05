@if($currentPhase === 'confirmed' && $result && ($result['recurring'] ?? false))
    <div class="mx-auto max-w-2xl text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full {{ $result['requires_payment'] ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' }}">
            @if($result['requires_payment'])
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            @else
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            @endif
        </span>
        <h2 data-booking-step-title tabindex="-1" class="mt-4 text-2xl font-black tracking-tight text-fg-strong outline-none">
            @if($result['requires_payment'])
                {{ count($result['bookings']) }} sessions reserved pending payment
            @else
                {{ count($result['bookings']) }} of {{ count($result['bookings']) + count($result['failures']) }} sessions confirmed
            @endif
        </h2>
        <p class="mt-2 text-sm leading-6 text-fg-muted">
            {{ $result['requires_payment'] ? 'Each session is reserved and paid separately. Complete payment for each one from My Bookings to confirm it.' : 'We have sent a confirmation to '.auth()->user()?->email.'.' }}
        </p>

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
                <p class="font-bold">Some sessions could not be booked</p>
                <ul class="mt-2 list-disc space-y-1 pl-4">
                    @foreach($result['failures'] as $when => $reason)
                        <li>{{ viewer_datetime($when) }} — {{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 flex flex-col items-center gap-3">
            <x-ui.button href="{{ $result['my_bookings_url'] }}" size="lg" class="w-full sm:w-auto">{{ $result['requires_payment'] ? 'Pay from My Bookings' : 'View my bookings' }}</x-ui.button>
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
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                    role="status"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @if($reservedUntil)
                        <span x-show="! ended">Reserved for <span x-text="label">{{ max(0, (int) ceil(now()->diffInMinutes($reservedUntil, false))) }} min</span></span>
                        <span x-show="ended" x-cloak>Reservation time has ended</span>
                    @else
                        Reserved temporarily
                    @endif
                </span>
                <h2 data-booking-step-title tabindex="-1" class="mt-3 text-2xl font-black tracking-tight text-fg-strong outline-none sm:text-3xl">Complete your payment</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">
                    Your lesson time is reserved while you complete payment.
                    @if($reservedUntil)
                        Reserved until {{ $reservedUntil->format('g:i A') }}.
                    @endif
                </p>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
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
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                        <div>
                            <dt class="sr-only">When</dt>
                            <dd class="font-semibold text-fg-strong">{{ $startsAt->format('l, j F') }}</dd>
                            <dd class="text-fg-muted">{{ $startsAt->format('g:i A') }} – {{ $endsAt->format('g:i A') }} · {{ $result['timezone'] }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.55 50.55 0 0112 13.489a50.55 50.55 0 017.74-3.342"/></svg>
                        <div>
                            <dt class="sr-only">Session</dt>
                            <dd class="font-semibold text-fg-strong">{{ $result['type']['name'] }}</dd>
                        </div>
                    </div>
                    @if($lockedInstructorName)
                        <div class="flex items-start gap-3">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
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

                    @if($paymentBanner || $paymentFailed)
                        <div class="booking-payment-error mt-4 rounded-xl border px-4 py-3" role="alert">
                            <p class="text-sm font-bold">{{ $paymentFailed ? 'Payment wasn’t completed.' : 'Payment needs attention' }}</p>
                            <p class="mt-0.5 text-sm leading-6">{{ $paymentBanner !== '' ? $paymentBanner : 'Your booking has not been confirmed. You can try again below.' }}</p>
                        </div>
                    @endif

                    <x-ui.button type="button" size="lg" class="booking-checkout-primary mt-4 w-full" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment" aria-describedby="checkout-secure-note">
                        <span wire:loading.remove wire:target="initiatePayment" class="inline-flex items-center gap-2">
                            {{ $paymentFailed ? 'Try payment again' : (($result['amount_formatted'] ?? null) ? 'Pay '.$result['amount_formatted'].' securely' : 'Pay now') }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </span>
                        <span wire:loading wire:target="initiatePayment" class="inline-flex items-center gap-2" role="status">
                            <x-ui.spinner size="sm" class="text-white" />
                            Preparing secure payment…
                        </span>
                    </x-ui.button>
                    <p id="checkout-secure-note" class="mt-2 flex items-start gap-1.5 text-xs leading-5 text-fg-muted">
                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        <span>Secure payment. Your booking is confirmed after the payment succeeds.</span>
                    </p>

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
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
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
                    <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">Total due</p>
                    <p class="text-lg font-black leading-6 text-fg-strong">{{ $result['amount_formatted'] ?? '—' }}</p>
                </div>
                <x-ui.button type="button" class="booking-checkout-primary shrink-0" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment" aria-label="Pay {{ $result['amount_formatted'] ?? '' }} securely">
                    <span wire:loading.remove wire:target="initiatePayment">Pay securely</span>
                    <span wire:loading wire:target="initiatePayment">Preparing…</span>
                </x-ui.button>
            </div>
        </div>
    @endif
@endif
