@php
    $isPaid = (bool) ($selectedType['is_paid'] ?? false);
    $stageIndex = collect($stages)->search(fn (array $stage): bool => $stage['key'] === $currentStage);
    $cta = match ($currentStage) {
        'learning' => [
            'action' => 'continueStage',
            'label' => 'Continue to schedule',
            'loading' => 'Loading…',
            'disabled' => ! $learningComplete,
            'hint' => 'Finish your learning details to continue',
        ],
        'schedule' => [
            'action' => 'continueStage',
            'label' => 'Review booking',
            'loading' => 'Loading…',
            'disabled' => $selectedSlotStartsAt === null,
            'hint' => 'Pick a date and time to continue',
        ],
        'review' => [
            'action' => 'submit',
            'label' => $isPaid && ! $packageEntitlementId ? 'Proceed to payment' : 'Confirm booking',
            'loading' => $isPaid ? 'Reserving your time…' : 'Booking…',
            'disabled' => $currentPhase === 'funding',
            'hint' => 'Choose how you would like to pay',
        ],
        default => null,
    };
    $awaitingPayment = $currentStage === 'outcome'
        && ! ($result['recurring'] ?? false)
        && ($result['requires_payment'] ?? false)
        && ($result['payment_status'] ?? null) !== 'paid'
        && ($result['status'] ?? null) !== 'cancelled';
    $footerPrice = match (true) {
        ! $learningComplete || $selectedType === null => null,
        (bool) $packageEntitlementId => 'Package',
        ! $isPaid => 'Free',
        $pricePreview !== [] => $pricePreview['total_formatted'],
        default => null,
    };
@endphp

<div class="min-h-screen bg-surface text-fg-strong" data-booking-wizard-page>
    <div
        class="mx-auto max-w-7xl px-4 pt-5 sm:px-6 lg:px-8 {{ $cta ? 'pb-28 lg:pb-10' : ($awaitingPayment ? 'pb-28 md:pb-10' : 'pb-10') }}"
        x-data
        x-init="
            if (Intl?.DateTimeFormat) {
                $wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
            }
        "
    >
        <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-black tracking-tight text-fg-strong sm:text-[1.75rem]">Book a Session</h1>
                <p class="mt-1 text-sm font-semibold text-fg-muted">
                    @if($learningSummary)
                        {{ $learningSummary }}
                    @else
                        Choose what you need help with, then pick a time that suits you.
                    @endif
                </p>
                @if($lockedInstructorName)
                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-full border border-indigo-300/40 bg-indigo-500/10 px-3 py-1 text-xs font-bold text-indigo-700 dark:text-indigo-200">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        With {{ $lockedInstructorName }}
                    </p>
                @endif
            </div>
            <p class="inline-flex shrink-0 items-center gap-2 self-start rounded-full border border-edge bg-surface-raised px-3 py-1.5 text-xs font-bold text-fg-muted">
                <svg class="h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="sr-only">Your time zone:</span>{{ $timezoneLabel }}
            </p>
        </header>

        <x-booking.progress :stages="$stages" :current="$currentStage" class="mt-5 rounded-2xl border border-edge bg-surface-raised px-4 py-3 sm:px-5 sm:py-4" />

        <div class="mt-5 grid grid-cols-1 gap-5 {{ $cta ? 'lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]' : '' }}">
            <div class="min-w-0">
                @if($cta)
                    <div class="mb-4 rounded-2xl border border-edge bg-surface-raised lg:hidden" x-data="{ open: false }">
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="booking-mobile-summary" class="flex min-h-12 w-full items-center justify-between gap-3 px-4 text-left focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">
                            <span class="min-w-0">
                                <span class="block text-[11px] font-black uppercase tracking-[0.14em] text-fg-muted">Your session</span>
                                <span class="block truncate text-sm font-bold text-fg-strong">{{ $scheduleSummary ?? $learningSummary ?? 'Nothing chosen yet' }}</span>
                            </span>
                            <span class="inline-flex shrink-0 items-center gap-1 text-xs font-bold text-indigo-600 dark:text-indigo-300">
                                <span x-text="open ? 'Hide' : 'Details'"></span>
                                <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            </span>
                        </button>
                        <div id="booking-mobile-summary" x-show="open" x-collapse x-cloak class="border-t border-edge px-4 pb-4 pt-3">
                            @include('livewire.frontend.booking.partials.summary')
                        </div>
                    </div>
                @endif

                <section class="booking-step-panel rounded-3xl border border-edge bg-surface-raised p-5 shadow-sm shadow-indigo-950/5 sm:p-7">
                    <p class="sr-only" aria-live="polite">Stage {{ ($stageIndex === false ? 0 : $stageIndex) + 1 }} of {{ count($stages) }}: {{ $stages[$stageIndex === false ? 0 : $stageIndex]['label'] }}</p>

                    @if($banner)
                        <x-ui.alert type="error" class="booking-error-alert mb-6">
                            <p class="font-black">We couldn't continue</p>
                            <p class="mt-1 leading-6">{{ $banner }}</p>
                        </x-ui.alert>
                    @endif

                    @if($currentStage === 'learning')
                        @include('livewire.frontend.booking.partials.learning-details')
                    @elseif($currentStage === 'schedule')
                        @include('livewire.frontend.booking.partials.schedule')
                    @elseif($currentStage === 'review')
                        @include('livewire.frontend.booking.partials.review')
                    @else
                        @include('livewire.frontend.booking.partials.confirmed')
                    @endif

                    @if($cta)
                        @include('livewire.frontend.booking.partials.actions')
                    @endif
                </section>
            </div>

            @if($cta)
                <aside class="hidden lg:block">
                    @include('livewire.frontend.booking.partials.summary', ['summaryClass' => 'sticky top-28 rounded-3xl border border-edge bg-surface-raised p-5 shadow-sm shadow-indigo-950/5'])
                </aside>
            @endif
        </div>
    </div>

    @if($cta)
        <div class="booking-mobile-footer fixed inset-x-0 bottom-0 z-40 border-t border-edge bg-surface-raised/95 px-4 py-3 backdrop-blur lg:hidden" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-3">
                <div class="min-w-0">
                    @if($footerPrice)
                        <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">{{ $recurring && $isPaid && ! $packageEntitlementId ? 'Per session' : 'Total' }}</p>
                        <p class="text-lg font-black leading-6 text-fg-strong">{{ $footerPrice }}</p>
                    @elseif($cta['disabled'])
                        <p class="text-xs font-medium text-fg-muted">{{ $cta['hint'] }}</p>
                    @endif
                </div>
                @include('livewire.frontend.booking.partials.cta', ['size' => 'md'])
            </div>
        </div>
    @endif
</div>

@script
@include('livewire.frontend.booking.partials.razorpay-checkout-script')
@include('livewire.frontend.booking.partials.stripe-checkout-script')
@endscript
