<div class="min-h-screen bg-slate-50 dark:bg-slate-950">
<div
    class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8"
    x-data
    x-init="
        if (Intl?.DateTimeFormat) {
            $wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
        }
    "
>
    <header class="text-center">
        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">Booking</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950 dark:text-white sm:text-4xl">Book a Session</h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
            Choose a subject, pick an available time, and confirm the student details.
        </p>
    </header>

    <nav class="mt-8" aria-label="Booking progress">
        <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7" role="list">
            @foreach($steps as $item)
                @php
                    $isCurrent = $step === $item['number'];
                    $isComplete = $step > $item['number'];
                @endphp
                <li>
                    <div
                        @if($isCurrent) aria-current="step" @endif
                        class="flex min-h-14 items-center gap-3 rounded-xl border px-3 py-2 text-sm transition
                            {{ $isCurrent ? 'border-indigo-500 bg-indigo-50 text-indigo-800 dark:border-indigo-300/60 dark:bg-indigo-400/10 dark:text-indigo-100' : '' }}
                            {{ $isComplete ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-300/30 dark:bg-emerald-400/10 dark:text-emerald-100' : '' }}
                            {{ ! $isCurrent && ! $isComplete ? 'border-slate-200 bg-white text-slate-500 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-400' : '' }}"
                    >
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold
                            {{ $isCurrent ? 'bg-indigo-600 text-white' : '' }}
                            {{ $isComplete ? 'bg-emerald-600 text-white' : '' }}
                            {{ ! $isCurrent && ! $isComplete ? 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300' : '' }}">
                            {{ $item['number'] }}
                        </span>
                        <span class="font-semibold">{{ $item['label'] }}</span>
                    </div>
                </li>
            @endforeach
        </ol>
    </nav>

    <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.04] sm:p-8">
        <p class="sr-only" aria-live="polite">Step {{ $step }} of 7: {{ $steps[$step - 1]['label'] }}</p>

        @if($banner)
            <x-ui.alert type="error" class="mb-6">{{ $banner }}</x-ui.alert>
        @endif

        @if($step === 1)
            <div>
                <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Choose a subject</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Select the area where the student needs support.</p>

                @if(empty($subjects))
                    <x-ui.empty-state title="No subjects available" description="Please check back soon." class="mt-6" />
                @else
                    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($subjects as $subjectOption)
                            <button
                                type="button"
                                wire:click="selectSubject(@js($subjectOption))"
                                aria-pressed="{{ $subject === $subjectOption ? 'true' : 'false' }}"
                                class="min-h-20 rounded-xl border px-4 py-3 text-center text-sm font-semibold capitalize transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30
                                    {{ $subject === $subjectOption ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-300/60 dark:bg-indigo-400/10 dark:text-indigo-100' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10' }}"
                            >
                                {{ str_replace(['_', '-'], ' ', $subjectOption) }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if($step === 2)
            <div>
                <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Choose a grade</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Which grade is the student in?</p>

                <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                    @foreach($grades as $gradeOption)
                        <button
                            type="button"
                            wire:click="selectGrade({{ $gradeOption }})"
                            aria-pressed="{{ $grade === $gradeOption ? 'true' : 'false' }}"
                            class="min-h-20 rounded-xl border px-4 py-3 text-center transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30
                                {{ $grade === $gradeOption ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-300/60 dark:bg-indigo-400/10 dark:text-indigo-100' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10' }}"
                        >
                            <span class="block text-xs text-slate-400">Grade</span>
                            <span class="text-lg font-bold">{{ $gradeOption }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @if($step === 3)
            <div>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Pick a date</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Days with availability are selectable.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="previousMonth" @disabled(! $canGoPreviousMonth) aria-label="Previous month" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">
                            <span aria-hidden="true">&lt;</span>
                        </button>
                        <button type="button" wire:click="nextMonth" @disabled(! $canGoNextMonth) aria-label="Next month" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">
                            <span aria-hidden="true">&gt;</span>
                        </button>
                    </div>
                </div>

                <div class="mt-6 text-center text-sm font-bold text-slate-950 dark:text-white" aria-live="polite">
                    {{ \Carbon\CarbonImmutable::parse($month . '-01')->format('F Y') }}
                </div>

                <div wire:loading.flex wire:target="selectGrade,previousMonth,nextMonth" class="mt-6 items-center justify-center gap-3 text-sm text-slate-500 dark:text-slate-400">
                    <x-ui.spinner size="sm" />
                    Checking availability...
                </div>

                <div wire:loading.remove wire:target="selectGrade,previousMonth,nextMonth" class="mt-4">
                    <div class="grid grid-cols-7 text-center text-xs font-bold uppercase tracking-wide text-slate-400" aria-hidden="true">
                        @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                            <span class="py-2">{{ $day }}</span>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 gap-1" role="group" aria-label="Choose a date">
                        @foreach($calendar as $cell)
                            <div class="aspect-square">
                                @if($cell)
                                    <button
                                        type="button"
                                        wire:click="selectDate(@js($cell['iso']))"
                                        @disabled(! $cell['available'])
                                        aria-label="{{ $cell['label'] }}{{ $cell['available'] ? ', available' : ', unavailable' }}"
                                        aria-pressed="{{ $cell['selected'] ? 'true' : 'false' }}"
                                        class="h-full w-full rounded-xl text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-not-allowed disabled:opacity-40 dark:focus-visible:ring-indigo-400/30
                                            {{ $cell['selected'] ? 'bg-indigo-600 text-white' : '' }}
                                            {{ ! $cell['selected'] && $cell['available'] ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-400/10 dark:text-indigo-100 dark:hover:bg-indigo-400/20' : '' }}
                                            {{ ! $cell['available'] ? 'bg-transparent text-slate-300 dark:text-slate-700' : '' }}"
                                    >
                                        {{ $cell['day'] }}
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if(empty($dates))
                        <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">No availability this month. Try the next month.</p>
                    @endif
                </div>
            </div>
        @endif

        @if($step === 4)
            <div>
                <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Choose an available slot</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Times are shown in {{ $timezone }}.
                </p>

                <div wire:loading.flex wire:target="selectDate" class="mt-6 items-center justify-center gap-3 text-sm text-slate-500 dark:text-slate-400">
                    <x-ui.spinner size="sm" />
                    Loading slots...
                </div>

                <div wire:loading.remove wire:target="selectDate" class="mt-6">
                    @if(empty($availableSlots))
                        <x-ui.empty-state title="No slots available" description="Pick another date to continue." />
                    @else
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach($availableSlots as $slotOption)
                                <button
                                    type="button"
                                    wire:click="selectSlot(@js($slotOption['starts_at']))"
                                    aria-pressed="{{ $selectedSlotStartsAt === $slotOption['starts_at'] ? 'true' : 'false' }}"
                                    class="rounded-xl border px-4 py-3 text-center transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30
                                        {{ $selectedSlotStartsAt === $slotOption['starts_at'] ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-300/60 dark:bg-indigo-400/10 dark:text-indigo-100' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10' }}"
                                >
                                    <span class="block font-bold">{{ \Carbon\CarbonImmutable::parse($slotOption['starts_at'])->timezone($timezone)->format('g:i A') }}</span>
                                    @if($slotOption['remaining_capacity'] !== null && $slotOption['remaining_capacity'] > 1)
                                        <span class="mt-1 block text-xs text-slate-400">{{ $slotOption['remaining_capacity'] }} seats left</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($step === 5)
            <div>
                <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Student details</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">We will send the confirmation and joining details by email.</p>

                <form wire:submit="review" class="mt-6 space-y-4" novalidate>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input name="name" label="Full name" wire:model.blur="name" autocomplete="name" required />
                        <x-ui.input name="email" type="email" label="Email" wire:model.blur="email" autocomplete="email" required />
                    </div>
                    <x-ui.input name="phone" type="tel" label="Phone" hint="Optional" wire:model.blur="phone" autocomplete="tel" />
                    <div>
                        <label for="booking-notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Notes</label>
                        <textarea id="booking-notes" wire:model.blur="notes" rows="4" class="block w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-sm transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100 dark:border-white/10 dark:bg-white/5 dark:text-slate-100 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20"></textarea>
                        @error('notes') <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.honeypot wire:model="website" />

                    <x-ui.turnstile :enabled="$turnstileEnabled" :site-key="$turnstileSiteKey" wire-model="cfTurnstileResponse" />

                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="review">
                        <span wire:loading.remove wire:target="review">Review booking</span>
                        <span wire:loading wire:target="review">Checking...</span>
                    </x-ui.button>
                </form>
            </div>
        @endif

        @if($step === 6)
            <div>
                <h2 data-booking-step-title tabindex="-1" class="text-xl font-bold text-slate-950 outline-none dark:text-white">Review booking</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Confirm the details before submitting.</p>

                <dl class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm dark:bg-white/5 sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Subject</dt><dd class="mt-1 font-semibold capitalize text-slate-900 dark:text-white">{{ str_replace(['_', '-'], ' ', (string) $subject) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Grade</dt><dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $grade }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Date</dt><dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Time</dt><dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone)->format('g:i A') : '' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Student</dt><dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Email</dt><dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $email }}</dd></div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <x-ui.button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Confirm booking</span>
                        <span wire:loading wire:target="submit">Booking...</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('step', 5)">Edit details</x-ui.button>
                </div>
            </div>
        @endif

        @if($step === 7 && $result)
            <div class="text-center">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                </span>
                <h2 data-booking-step-title tabindex="-1" class="mt-4 text-2xl font-bold text-slate-950 outline-none dark:text-white">Booking {{ $result['status'] === 'confirmed' ? 'confirmed' : 'requested' }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Reference: <span class="font-mono font-bold text-slate-800 dark:text-slate-100">{{ $result['reference'] }}</span></p>

                <dl class="mx-auto mt-6 max-w-md space-y-2 rounded-2xl bg-slate-50 p-5 text-left text-sm dark:bg-white/5">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500 dark:text-slate-400">Session</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ $result['type']['name'] }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500 dark:text-slate-400">When</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ \Carbon\CarbonImmutable::parse($result['starts_at'])->timezone($result['timezone'])->format('M j, Y g:i A') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500 dark:text-slate-400">Timezone</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ $result['timezone'] }}</dd></div>
                </dl>

                @if($result['manage_token'])
                    <div class="mx-auto mt-5 max-w-md rounded-2xl border border-amber-200 bg-amber-50 p-4 text-left dark:border-amber-300/30 dark:bg-amber-400/10">
                        <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">Manage code</p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-200">Save this code now. It is shown only once.</p>
                        <code class="mt-3 block overflow-x-auto rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-700 ring-1 ring-amber-200 dark:bg-slate-950 dark:text-slate-100 dark:ring-amber-300/30">{{ $result['manage_token'] }}</code>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    @if($result['manage_url'])
                        <x-ui.button href="{{ $result['manage_url'] }}">Manage booking</x-ui.button>
                    @elseif($result['my_bookings_url'])
                        <x-ui.button href="{{ $result['my_bookings_url'] }}">View my bookings</x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="ghost" wire:click="restart">Book another session</x-ui.button>
                </div>
            </div>
        @endif

        @if($step > 1 && $step < 7)
            <div class="mt-8 border-t border-slate-200 pt-5 dark:border-white/10">
                <button type="button" wire:click="back" class="text-sm font-semibold text-slate-500 transition hover:text-slate-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:text-slate-400 dark:hover:text-white">
                    Back
                </button>
            </div>
        @endif
    </section>
</div>
</div>
