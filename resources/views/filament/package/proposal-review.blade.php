{{--
    Phase 4D — the read-only answer to "what exactly am I approving?".

    Every value here is the proposal's own FROZEN snapshot, never a live
    re-resolve: the academic context was locked at submission and the
    price/quantity/validity snapshot was locked alongside it. That is
    deliberate — an admin must see the offer as the student will receive
    it, not as current master data happens to read today.

    Read-only by construction. Admin may approve, reject, or override the
    price through the dedicated actions; the academic identity itself is
    never editable here. If the context is wrong the correct remedy is to
    reject and require a new proposal, not to mutate a submitted
    historical offer.
--}}
@php
    $context = $record->academicContext;
    $money = fn (?int $minor): string => $minor === null
        ? '—'
        : \App\Support\MoneyFormatter::format($minor, $record->currency_code ?? 'USD');
@endphp

<div class="space-y-6 text-sm">

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Parties</h3>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Student</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->student?->name ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Instructor</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->instructor?->name ?? '—' }}</dd></div>
        </dl>
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Academic context</h3>

        @if ($context)
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                <div><dt class="text-gray-500 dark:text-gray-400">Country</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->country_name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Education System</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->education_system_name ?? '—' }}</dd></div>
                {{-- Student-facing terminology comes from the system itself: Class / Grade / Year. --}}
                <div><dt class="text-gray-500 dark:text-gray-400">{{ $context->level_term ?: 'Level' }}</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->level_display ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Subject</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->subject_name ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-gray-500 dark:text-gray-400">Curriculum</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->curriculum_name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Curriculum Version</dt><dd class="font-medium text-gray-950 dark:text-white">v{{ $context->curriculum_version_number ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Internal Academic Level</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $context->academic_level_name ?? '—' }}</dd></div>
            </dl>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Frozen at submission. Publishing a newer curriculum version later does not change this package.
            </p>
        @else
            {{-- A legacy proposal, created before country-aware packages were
                 enabled for this student's country. Shown honestly rather than
                 back-filled with a guess, and deliberately ineligible for the
                 structured package-funded booking path. --}}
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                <div><dt class="text-gray-500 dark:text-gray-400">Subject</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->subject?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Academic Level</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->academicLevel?->name ?? 'Any level' }}</dd></div>
            </dl>

            <p class="mt-3 text-xs text-warning-600 dark:text-warning-400">
                Legacy proposal — no structured academic context. This package cannot be used
                through the country-aware package booking flow.
            </p>
        @endif
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Package offer</h3>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Offer</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->packageBenefitRule?->name ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Lesson Duration</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->duration_minutes ? $record->duration_minutes.' min' : '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Paid Lessons</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->paid_quantity ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Bonus Lessons</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->bonus_quantity ?? 0 }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Total Lessons</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->total_quantity ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Validity</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->validity_days ? $record->validity_days.' days' : 'No time limit' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Quantities and validity are copied from the package offer at submission — a later
            edit to that offer never rewrites this proposal.
        </p>
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pricing</h3>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Currency</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->currency_code ?? '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Unit Lesson Price</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $money($record->unit_price_minor) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Calculated Price</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $money($record->calculated_price_minor) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Override Price</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $money($record->override_price_minor) }}</dd></div>
            <div class="col-span-2"><dt class="text-gray-500 dark:text-gray-400">Final Price</dt><dd class="text-base font-bold text-gray-950 dark:text-white">{{ $money($record->final_price_minor) }}</dd></div>
            @if ($record->override_reason)
                <div class="col-span-2"><dt class="text-gray-500 dark:text-gray-400">Override Reason</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $record->override_reason }}</dd></div>
            @endif
        </dl>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Calculated as unit lesson price x paid lessons. Bonus lessons never add to the
            student's price, and none of this affects what the instructor earns.
        </p>
    </section>

</div>
