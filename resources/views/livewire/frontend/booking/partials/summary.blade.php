@php
    $subjectLabel = $subject ? ucfirst(str_replace(['_', '-'], ' ', $subject)) : null;
    $levelLabel = $academicFlowActive ? ($selectedLevel['display_label'] ?? null) : ($grade ? 'Grade '.$grade : null);
    $contextLine = implode(' • ', array_filter([$levelLabel, $selectedCurriculum['name'] ?? null]));
    $slotStart = $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone) : null;
    $slotEnd = $slotStart && ($selectedSlot['ends_at'] ?? null) ? \Carbon\CarbonImmutable::parse($selectedSlot['ends_at'])->timezone($timezone) : null;
    $isPaid = (bool) ($selectedType['is_paid'] ?? false);
@endphp

<div class="booking-summary-card {{ $summaryClass ?? '' }}">
    <h2 class="text-[11px] font-black uppercase tracking-[0.14em] text-fg-muted">Your session</h2>

    @if($subjectLabel)
        <p class="mt-3 text-lg font-black leading-6 text-fg-strong">{{ $subjectLabel }}</p>
        @if($contextLine)
            <p class="mt-0.5 text-sm text-fg-muted">{{ $contextLine }}</p>
        @endif
    @else
        <p class="mt-3 text-sm leading-6 text-fg-muted">Choose what you would like help with and your session details will appear here.</p>
    @endif

    <dl class="mt-4 space-y-3 text-sm">
        @if($selectedType)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Session</dt>
                <dd class="text-right font-semibold text-fg-strong">{{ $selectedType['name'] }}</dd>
            </div>
        @endif
        @if($isPaid && $billingModeChosen)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Schedule</dt>
                <dd class="text-right font-semibold text-fg-strong">
                    @if($recurring && $frequency)
                        {{ ucfirst($frequency) }} · {{ $occurrences }} sessions
                    @elseif($recurring)
                        Repeating
                    @else
                        One-time
                    @endif
                </dd>
            </div>
        @endif
        @if($slotStart)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">{{ $recurring ? 'First date' : 'Date' }}</dt>
                <dd class="text-right font-semibold text-fg-strong">{{ $slotStart->format('l, j F') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Time</dt>
                <dd class="text-right font-semibold text-fg-strong">{{ $slotStart->format('g:i A') }}@if($slotEnd) – {{ $slotEnd->format('g:i A') }}@endif</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Timezone</dt>
                <dd class="text-right font-semibold text-fg-strong">{{ $timezone }}</dd>
            </div>
        @endif
        @if($lockedInstructorName)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Instructor</dt>
                <dd class="text-right font-semibold text-fg-strong">{{ $lockedInstructorName }}</dd>
            </div>
        @elseif($learningComplete)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-fg-muted">Instructor</dt>
                <dd class="text-right font-semibold text-fg-strong">Matched to your selection</dd>
            </div>
        @endif
    </dl>

    @if($selectedType && $learningComplete)
        <div class="mt-4 border-t border-edge pt-4 text-sm">
            @if($packageEntitlementId)
                <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold text-fg-muted">Total</span>
                    <span class="text-right font-black text-emerald-600 dark:text-emerald-300">Covered by package</span>
                </div>
                @if($selectedFunding)
                    <p class="mt-1 text-xs text-fg-muted">{{ $selectedFunding['name'] }}</p>
                @endif
            @elseif(! $isPaid)
                <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold text-fg-muted">Total</span>
                    <span class="text-lg font-black text-fg-strong">Free</span>
                </div>
            @elseif($pricePreview !== [])
                <dl class="space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-fg-muted">Session fee</dt>
                        <dd class="font-semibold text-fg-strong">{{ $pricePreview['base_formatted'] }}</dd>
                    </div>
                    @if($pricePreview['discount_formatted'])
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-fg-muted">Discount</dt>
                            <dd class="font-semibold text-emerald-600 dark:text-emerald-300">− {{ $pricePreview['discount_formatted'] }}</dd>
                        </div>
                    @endif
                    @if($pricePreview['tax_formatted'])
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-fg-muted">Tax</dt>
                            <dd class="font-semibold text-fg-strong">{{ $pricePreview['tax_formatted'] }}</dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3 border-t border-edge pt-2">
                        <dt class="font-semibold text-fg-muted">{{ $recurring ? 'Per session' : 'Total' }}</dt>
                        <dd class="text-lg font-black text-fg-strong">{{ $pricePreview['total_formatted'] }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs leading-5 text-fg-faint">
                    @if($recurring)
                        Each session is reserved and paid separately.
                    @elseif(! $lockedInstructorName)
                        Standard rate. The final amount is confirmed when your time is reserved.
                    @else
                        The amount is confirmed when your time is reserved.
                    @endif
                </p>
            @else
                <p class="text-xs leading-5 text-fg-faint">The price is shown once your time is reserved.</p>
            @endif
        </div>
    @endif
</div>
