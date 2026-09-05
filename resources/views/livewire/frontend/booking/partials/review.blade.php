@php
    $isPaid = (bool) ($selectedType['is_paid'] ?? false);
    $subjectLabel = $subject ? ucfirst(str_replace(['_', '-'], ' ', $subject)) : null;
    $levelLabel = $academicFlowActive ? ($selectedLevel['display_label'] ?? null) : ($grade ? 'Grade '.$grade : null);
    $slotStart = $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone) : null;
    $slotEnd = $slotStart && ($selectedSlot['ends_at'] ?? null) ? \Carbon\CarbonImmutable::parse($selectedSlot['ends_at'])->timezone($timezone) : null;
    $fundingPending = $currentPhase === 'funding';
@endphp

<div class="space-y-6">
    <div>
        <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black tracking-tight text-fg-strong outline-none">Review your booking</h2>
        <p class="mt-1.5 text-sm leading-6 text-fg-muted">
            @if($isPaid && ! $packageEntitlementId)
                Check everything below, then continue to secure payment. Your time is held while you pay, and the session is confirmed once payment succeeds.
            @else
                Check everything below, add any notes for the instructor, then confirm.
            @endif
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-2xl border border-edge bg-surface p-4" aria-labelledby="review-learning">
            <div class="flex items-center justify-between gap-3">
                <h3 id="review-learning" class="text-[11px] font-black uppercase tracking-wide text-fg-muted">Learning</h3>
                <button type="button" wire:click="editStage('learning')" class="min-h-8 rounded px-1 text-sm font-bold text-indigo-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 dark:text-indigo-300">Edit<span class="sr-only"> learning details</span></button>
            </div>
            <dl class="mt-2 space-y-1.5 text-sm">
                @if($selectedType)<div class="flex justify-between gap-3"><dt class="text-fg-muted">Session</dt><dd class="text-right font-semibold text-fg-strong">{{ $selectedType['name'] }}</dd></div>@endif
                @if($subjectLabel)<div class="flex justify-between gap-3"><dt class="text-fg-muted">Subject</dt><dd class="text-right font-semibold text-fg-strong">{{ $subjectLabel }}</dd></div>@endif
                @if($levelLabel)<div class="flex justify-between gap-3"><dt class="text-fg-muted">{{ $academicFlowActive ? $levelTermSingular : 'Grade' }}</dt><dd class="text-right font-semibold text-fg-strong">{{ $levelLabel }}</dd></div>@endif
                @if($selectedCurriculum)<div class="flex justify-between gap-3"><dt class="text-fg-muted">Curriculum</dt><dd class="text-right font-semibold text-fg-strong">{{ $selectedCurriculum['name'] }}</dd></div>@endif
            </dl>
        </section>

        <section class="rounded-2xl border border-edge bg-surface p-4" aria-labelledby="review-schedule">
            <div class="flex items-center justify-between gap-3">
                <h3 id="review-schedule" class="text-[11px] font-black uppercase tracking-wide text-fg-muted">Schedule</h3>
                <button type="button" wire:click="editStage('schedule')" class="min-h-8 rounded px-1 text-sm font-bold text-indigo-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 dark:text-indigo-300">Edit<span class="sr-only"> schedule</span></button>
            </div>
            <dl class="mt-2 space-y-1.5 text-sm">
                @if($slotStart)
                    <div class="flex justify-between gap-3"><dt class="text-fg-muted">{{ $recurring ? 'First date' : 'Date' }}</dt><dd class="text-right font-semibold text-fg-strong">{{ $slotStart->format('l, j F Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-fg-muted">Time</dt><dd class="text-right font-semibold text-fg-strong">{{ $slotStart->format('g:i A') }}@if($slotEnd) – {{ $slotEnd->format('g:i A') }}@endif</dd></div>
                @endif
                <div class="flex justify-between gap-3"><dt class="text-fg-muted">Timezone</dt><dd class="text-right font-semibold text-fg-strong">{{ $timezone }}</dd></div>
                @if($recurrenceSummary)
                    <div class="flex justify-between gap-3"><dt class="text-fg-muted">Repeats</dt><dd class="text-right font-semibold text-fg-strong">{{ $recurrenceSummary }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="rounded-2xl border border-edge bg-surface p-4" aria-labelledby="review-instructor">
            <h3 id="review-instructor" class="text-[11px] font-black uppercase tracking-wide text-fg-muted">Instructor</h3>
            <p class="mt-2 text-sm font-semibold text-fg-strong">{{ $lockedInstructorName ?? 'Matched to your selection' }}</p>
            @unless($lockedInstructorName)
                <p class="mt-1 text-xs leading-5 text-fg-muted">An eligible instructor for this subject, {{ \Illuminate\Support\Str::lower($academicFlowActive ? $levelTermSingular : 'grade') }} and time is assigned when you confirm.</p>
            @endunless
        </section>

        <section class="rounded-2xl border border-edge bg-surface p-4" aria-labelledby="review-pricing">
            <h3 id="review-pricing" class="text-[11px] font-black uppercase tracking-wide text-fg-muted">Pricing</h3>
            @if(! $isPaid)
                <p class="mt-2 text-lg font-black text-fg-strong">Free</p>
                <p class="text-xs text-fg-muted">No payment is needed for a demo session.</p>
            @elseif($packageEntitlementId)
                <p class="mt-2 text-lg font-black text-emerald-600 dark:text-emerald-300">Covered by package</p>
                @if($selectedFunding)<p class="text-xs text-fg-muted">{{ $selectedFunding['name'] }}</p>@endif
            @elseif($pricePreview !== [])
                <dl class="mt-2 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-fg-muted">Session fee</dt><dd class="font-semibold text-fg-strong">{{ $pricePreview['base_formatted'] }}</dd></div>
                    @if($pricePreview['discount_formatted'])<div class="flex justify-between gap-3"><dt class="text-fg-muted">Discount</dt><dd class="font-semibold text-emerald-600 dark:text-emerald-300">− {{ $pricePreview['discount_formatted'] }}</dd></div>@endif
                    @if($pricePreview['tax_formatted'])<div class="flex justify-between gap-3"><dt class="text-fg-muted">Tax</dt><dd class="font-semibold text-fg-strong">{{ $pricePreview['tax_formatted'] }}</dd></div>@endif
                    <div class="flex justify-between gap-3 border-t border-edge pt-1.5"><dt class="font-semibold text-fg-muted">{{ $recurring ? 'Per session' : 'Total payable' }}</dt><dd class="text-lg font-black text-fg-strong">{{ $pricePreview['total_formatted'] }}</dd></div>
                </dl>
                <p class="mt-1 text-xs leading-5 text-fg-faint">{{ $recurring ? 'Each session is reserved and paid separately from My Bookings.' : 'The final amount is confirmed when your time is reserved.' }}</p>
            @else
                <p class="mt-2 text-sm text-fg-muted">The price is confirmed when your time is reserved.</p>
            @endif
        </section>
    </div>

    @if($fundingOptions !== [])
        <section class="rounded-2xl border border-indigo-300/40 bg-indigo-500/5 p-4 sm:p-5" aria-labelledby="review-funding">
            <h3 id="review-funding" class="text-base font-black text-fg-strong">How would you like to pay?</h3>
            <p class="mt-1 text-sm text-fg-muted">You have a package that covers this lesson. Use it, or pay for this lesson on its own.</p>
            <div class="mt-3 space-y-3">
                @foreach($fundingOptions as $option)
                    <x-booking.option-card
                        wire:click="selectFunding('{{ $option['id'] }}')"
                        :selected="! $fundingPending && $packageEntitlementId === $option['id']"
                        :title="'Use package — '.$option['name']"
                    >
                        <x-slot:description>
                            {{ implode(' · ', array_filter([
                                $option['subject_name'] ?? null,
                                $option['level_display'] ?? null,
                                isset($option['available_to_book']) ? $option['available_to_book'].' '.\Illuminate\Support\Str::plural('lesson', (int) $option['available_to_book']).' available' : null,
                                ! empty($option['expires_at']) ? 'valid until '.\Carbon\CarbonImmutable::parse($option['expires_at'])->timezone($timezone)->format('j F Y') : null,
                            ])) }}
                        </x-slot:description>
                    </x-booking.option-card>
                @endforeach
                <x-booking.option-card
                    wire:click="selectFunding('')"
                    :selected="! $fundingPending && $packageEntitlementId === null"
                    title="Pay for this lesson"
                    description="Keep your package for another time."
                />
            </div>
        </section>
    @endif

    <div>
        <label for="booking-notes" class="mb-1.5 block text-sm font-semibold text-fg">Notes for the instructor <span class="font-normal text-fg-faint">(optional)</span></label>
        <textarea id="booking-notes" wire:model.blur="notes" rows="3" placeholder="Anything the instructor should know before the session" class="block w-full rounded-xl border border-edge bg-surface-raised px-3.5 py-2.5 text-sm text-fg shadow-sm transition placeholder:text-fg-faint focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20" @error('notes') aria-invalid="true" aria-describedby="booking-notes-error" @enderror></textarea>
        @error('notes') <p id="booking-notes-error" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300" role="alert">{{ $message }}</p> @enderror
    </div>

    <section class="rounded-2xl bg-surface p-4 text-xs leading-5 text-fg-muted" aria-labelledby="review-policies">
        <h3 id="review-policies" class="text-[11px] font-black uppercase tracking-wide text-fg-muted">Good to know</h3>
        <ul class="mt-1.5 list-disc space-y-1 pl-4">
            @if($isPaid)
                <li>Cancel at least {{ $policy['cancellation_window_hours'] }} {{ \Illuminate\Support\Str::plural('hour', $policy['cancellation_window_hours']) }} before the start for a full refund.</li>
            @endif
            <li>You can reschedule a session up to {{ $policy['reschedule_limit'] }} {{ \Illuminate\Support\Str::plural('time', $policy['reschedule_limit']) }} from My Bookings.</li>
            @if($isPaid && ! $packageEntitlementId)
                <li>Payment is processed securely by our payment partner. Your session is confirmed only after payment succeeds.</li>
            @endif
        </ul>
    </section>
</div>
