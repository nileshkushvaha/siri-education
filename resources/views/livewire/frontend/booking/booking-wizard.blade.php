<div class="min-h-screen bg-slate-950 text-slate-100">
    <div
        class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
        x-data
        x-init="
            if (Intl?.DateTimeFormat) {
                $wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
            }
        "
    >
        <header class="relative overflow-hidden rounded-3xl border border-white/10 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8 lg:p-10">
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-400 via-emerald-300 to-fuchsia-400" aria-hidden="true"></div>

            <div class="grid gap-8 lg:grid-cols-3 lg:items-end">
                <div class="lg:col-span-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-indigo-200">Session Booking</p>
                    <h1 class="mt-3 max-w-3xl text-4xl font-black tracking-tight text-white sm:text-5xl">Book a Session</h1>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-slate-300 sm:text-lg">
                        Choose the learning focus, pick an open time, and confirm the details. We will keep the flow simple and show the full summary before anything is booked.
                    </p>

                    @if($lockedInstructorName)
                        <p class="mt-5 inline-flex items-center rounded-full bg-indigo-400/10 px-4 py-2 text-sm font-bold text-indigo-100 ring-1 ring-indigo-300/20">
                            Booking with {{ $lockedInstructorName }}
                        </p>
                    @endif
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-5">
                    <p class="text-sm font-bold text-white">How it works</p>
                    <div class="mt-4 space-y-3 text-sm text-slate-300">
                        <div class="flex gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-500/20 text-xs font-black text-indigo-100">1</span>
                            <p>Select subject, grade, and time.</p>
                        </div>
                        <div class="flex gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/20 text-xs font-black text-emerald-100">2</span>
                            <p>Share the student details and notes.</p>
                        </div>
                        <div class="flex gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-fuchsia-500/20 text-xs font-black text-fuchsia-100">3</span>
                            <p>Review everything, then confirm.</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="{{ ($step >= 2 && $step <= 6) ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                <nav aria-label="Booking progress">
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
                                        {{ $isCurrent ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : '' }}
                                        {{ $isComplete ? 'border-emerald-300/30 bg-emerald-400/10 text-emerald-100' : '' }}
                                        {{ ! $isCurrent && ! $isComplete ? 'border-white/10 bg-white/[0.04] text-slate-400' : '' }}"
                                >
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold
                                        {{ $isCurrent ? 'bg-indigo-500 text-white' : '' }}
                                        {{ $isComplete ? 'bg-emerald-500 text-white' : '' }}
                                        {{ ! $isCurrent && ! $isComplete ? 'bg-white/10 text-slate-300' : '' }}">
                                        {{ $isComplete ? 'OK' : $item['number'] }}
                                    </span>
                                    <span class="font-semibold">{{ $item['label'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </nav>

                <section class="mt-6 rounded-3xl border border-white/10 bg-white/[0.04] p-5 shadow-2xl shadow-black/20 sm:p-8">
                    <p class="sr-only" aria-live="polite">Step {{ $step }} of 7: {{ $steps[$step - 1]['label'] }}</p>

                    @if($banner)
                        <x-ui.alert type="error" class="mb-6">{{ $banner }}</x-ui.alert>
                    @endif

                    @if($step === 1)
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a subject</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Start with the area where the student needs support. You can refine the details later with the instructor.</p>

                            @if(empty($subjects))
                                <x-ui.empty-state title="No subjects available" description="Please check back soon." class="mt-6" />
                            @else
                                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach($subjects as $subjectOption)
                                        <button
                                            type="button"
                                            wire:click="selectSubject(@js($subjectOption))"
                                            aria-pressed="{{ $subject === $subjectOption ? 'true' : 'false' }}"
                                            class="min-h-20 rounded-2xl border px-4 py-3 text-center text-sm font-bold capitalize transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                {{ $subject === $subjectOption ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
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
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a grade</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">This helps us match the session level to the student.</p>

                            <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                                @foreach($grades as $gradeOption)
                                    <button
                                        type="button"
                                        wire:click="selectGrade({{ $gradeOption }})"
                                        aria-pressed="{{ $grade === $gradeOption ? 'true' : 'false' }}"
                                        class="min-h-20 rounded-2xl border px-4 py-3 text-center transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                            {{ $grade === $gradeOption ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                    >
                                        <span class="block text-xs text-slate-500">Grade</span>
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
                                    <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Pick a date</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-400">Highlighted days have open times for this session.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="previousMonth" @disabled(! $canGoPreviousMonth) aria-label="Previous month" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40">
                                        <span aria-hidden="true">&lt;</span>
                                    </button>
                                    <button type="button" wire:click="nextMonth" @disabled(! $canGoNextMonth) aria-label="Next month" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40">
                                        <span aria-hidden="true">&gt;</span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-6 text-center text-sm font-bold text-white" aria-live="polite">
                                {{ \Carbon\CarbonImmutable::parse($month . '-01')->format('F Y') }}
                            </div>

                            <div wire:loading.flex wire:target="selectGrade,previousMonth,nextMonth" class="mt-6 items-center justify-center gap-3 text-sm text-slate-400">
                                <x-ui.spinner size="sm" />
                                Checking availability...
                            </div>

                            <div wire:loading.remove wire:target="selectGrade,previousMonth,nextMonth" class="mt-4">
                                <div class="grid grid-cols-7 text-center text-xs font-bold uppercase tracking-wide text-slate-500" aria-hidden="true">
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
                                                    class="h-full w-full rounded-xl text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30 disabled:cursor-not-allowed disabled:opacity-40
                                                        {{ $cell['selected'] ? 'bg-indigo-500 text-white' : '' }}
                                                        {{ ! $cell['selected'] && $cell['available'] ? 'bg-indigo-400/10 text-indigo-100 hover:bg-indigo-400/20' : '' }}
                                                        {{ ! $cell['available'] ? 'bg-transparent text-slate-700' : '' }}"
                                                >
                                                    {{ $cell['day'] }}
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if(empty($dates))
                                    <p class="mt-5 text-center text-sm text-slate-400">No open times this month. Try the next month.</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($step === 4)
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a time</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                All times are shown in your local timezone: {{ $timezone }}.
                            </p>

                            <div wire:loading.flex wire:target="selectDate" class="mt-6 items-center justify-center gap-3 text-sm text-slate-400">
                                <x-ui.spinner size="sm" />
                                Loading times...
                            </div>

                            <div wire:loading.remove wire:target="selectDate" class="mt-6">
                                @if(empty($availableSlots))
                                    <x-ui.empty-state title="No times available" description="Pick another date to continue." />
                                @else
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        @foreach($availableSlots as $slotOption)
                                            <button
                                                type="button"
                                                wire:click="selectSlot(@js($slotOption['starts_at']))"
                                                aria-pressed="{{ $selectedSlotStartsAt === $slotOption['starts_at'] ? 'true' : 'false' }}"
                                                class="rounded-2xl border px-4 py-3 text-center transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                    {{ $selectedSlotStartsAt === $slotOption['starts_at'] ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
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
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Your details</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">We will send the confirmation and joining details to this email.</p>

                            <form wire:submit="review" class="mt-6 space-y-4" novalidate>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-ui.input name="name" label="Full name" wire:model.blur="name" autocomplete="name" required />
                                    <x-ui.input name="email" type="email" label="Email" wire:model.blur="email" autocomplete="email" required />
                                </div>
                                <x-ui.input name="phone" type="tel" label="Phone" hint="Optional" wire:model.blur="phone" autocomplete="tel" />
                                <div>
                                    <label for="booking-notes" class="mb-1.5 block text-sm font-medium text-slate-200">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                                    <textarea id="booking-notes" wire:model.blur="notes" rows="4" placeholder="Anything the instructor should know before the session" class="block w-full rounded-xl border border-white/10 bg-slate-900/70 px-3.5 py-2 text-sm text-slate-100 shadow-sm transition placeholder:text-slate-500 focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20"></textarea>
                                    @error('notes') <p class="mt-1.5 text-xs font-medium text-red-300">{{ $message }}</p> @enderror
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
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Review your booking</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Double-check everything below, then confirm to book the session.</p>

                            <dl class="mt-6 grid gap-3 rounded-2xl bg-slate-900/70 p-4 text-sm ring-1 ring-white/10 sm:grid-cols-2">
                                @if($selectedType)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Session</dt><dd class="mt-1 font-semibold text-white">{{ $selectedType['name'] }}</dd></div>
                                @endif
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject</dt><dd class="mt-1 font-semibold capitalize text-white">{{ str_replace(['_', '-'], ' ', (string) $subject) }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Grade</dt><dd class="mt-1 font-semibold text-white">{{ $grade }}</dd></div>
                                @if($lockedInstructorName)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instructor</dt><dd class="mt-1 font-semibold text-white">{{ $lockedInstructorName }}</dd></div>
                                @endif
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Date</dt><dd class="mt-1 font-semibold text-white">{{ $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '' }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Time</dt><dd class="mt-1 font-semibold text-white">{{ $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone)->format('g:i A') : '' }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Student</dt><dd class="mt-1 font-semibold text-white">{{ $name }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 font-semibold text-white">{{ $email }}</dd></div>
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
                            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-400/15 text-emerald-200">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <h2 data-booking-step-title tabindex="-1" class="mt-4 text-2xl font-black text-white outline-none">Booking {{ $result['status'] === 'confirmed' ? 'confirmed' : 'requested' }}</h2>
                            <p class="mt-2 text-sm text-slate-400">Reference: <span class="font-mono font-bold text-slate-100">{{ $result['reference'] }}</span></p>
                            <p class="mt-1 text-sm text-slate-400">We have sent a confirmation to {{ $email }}.</p>

                            <dl class="mx-auto mt-6 max-w-md space-y-2 rounded-2xl bg-slate-900/70 p-5 text-left text-sm ring-1 ring-white/10">
                                <div class="flex justify-between gap-4"><dt class="text-slate-400">Session</dt><dd class="font-semibold text-white">{{ $result['type']['name'] }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-400">When</dt><dd class="font-semibold text-white">{{ \Carbon\CarbonImmutable::parse($result['starts_at'])->timezone($result['timezone'])->format('M j, Y g:i A') }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-400">Timezone</dt><dd class="font-semibold text-white">{{ $result['timezone'] }}</dd></div>
                            </dl>

                            @if($result['manage_token'])
                                <div class="mx-auto mt-5 max-w-md rounded-2xl border border-amber-300/30 bg-amber-400/10 p-4 text-left">
                                    <p class="text-xs font-bold uppercase tracking-wide text-amber-200">Manage code</p>
                                    <p class="mt-1 text-xs text-amber-200">Save this code now. It is shown only once.</p>
                                    <code class="mt-3 block overflow-x-auto rounded-lg bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100 ring-1 ring-amber-300/30">{{ $result['manage_token'] }}</code>
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
                        <div class="mt-8 border-t border-white/10 pt-5">
                            <button type="button" wire:click="back" class="text-sm font-semibold text-slate-400 transition hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30">
                                &larr; Back
                            </button>
                        </div>
                    @endif
                </section>
            </div>

            @if($step >= 2 && $step <= 6)
                <aside class="lg:col-span-1">
                    <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5 shadow-2xl shadow-black/20 sm:p-6 lg:sticky lg:top-8">
                        <h2 class="text-xs font-bold uppercase tracking-wide text-slate-400">Your booking so far</h2>

                        <dl class="mt-4 space-y-4 text-sm">
                            @if($lockedInstructorName)
                                <div class="flex items-start justify-between gap-3">
                                    <dt class="text-slate-400">Instructor</dt>
                                    <dd class="text-right font-semibold text-white">{{ $lockedInstructorName }}</dd>
                                </div>
                            @endif
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-slate-400">Subject</dt>
                                <dd class="text-right font-semibold capitalize text-white">{{ $subject ? str_replace(['_', '-'], ' ', $subject) : '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-slate-400">Grade</dt>
                                <dd class="text-right font-semibold text-white">{{ $grade ?? '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-slate-400">Date</dt>
                                <dd class="text-right font-semibold text-white">{{ $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-slate-400">Time</dt>
                                <dd class="text-right font-semibold text-white">{{ $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone)->format('g:i A') : '-' }}</dd>
                            </div>
                        </dl>

                        <p class="mt-5 border-t border-white/10 pt-4 text-xs leading-5 text-slate-400">
                            Nothing is booked yet. You can go back and change any detail before you confirm.
                        </p>
                    </div>
                </aside>
            @endif
        </div>
    </div>
</div>
