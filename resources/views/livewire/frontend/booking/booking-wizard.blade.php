<div class="min-h-screen bg-[#f8fbff] text-slate-900" data-booking-wizard-page>
    <div
        class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
        x-data
        x-init="
            if (Intl?.DateTimeFormat) {
                $wire.setTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
            }
        "
    >
        <header class="relative overflow-hidden rounded-3xl border border-white/10 bg-[#080b24] px-5 py-5 text-white shadow-xl shadow-indigo-950/20 sm:px-7 sm:py-6" data-booking-reveal>
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-400 via-emerald-300 to-fuchsia-400" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -left-24 top-0 h-40 w-40 rounded-full bg-cyan-400/15 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -right-24 bottom-0 h-44 w-44 rounded-full bg-fuchsia-500/15 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#a5b4fc .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>

            <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-[0.16em] text-cyan-200">Session booking</p>
                    <h1 class="mt-1.5 text-2xl font-black tracking-tight text-white sm:text-3xl">Book a Session</h1>
                    <p class="mt-1.5 max-w-3xl text-sm leading-6 text-slate-300">
                        Choose your learning focus and an available time, then review before confirming.
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2 text-xs font-bold text-slate-200 sm:max-w-sm sm:justify-end">
                    @if($lockedInstructorName)
                        <span class="rounded-full border border-indigo-300/20 bg-indigo-400/10 px-3 py-2 text-indigo-100">
                            With {{ $lockedInstructorName }}
                        </span>
                    @endif
                    <span class="rounded-full border border-white/10 bg-white/[0.06] px-3 py-2">
                        {{ $timezone }}
                    </span>
                </div>
            </div>
        </header>

        <div class="booking-workspace mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3" data-booking-reveal>
            <div class="{{ ! in_array($currentPhase, ['mode', 'confirmed']) ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                <nav aria-label="Booking progress" class="rounded-3xl border border-indigo-100 bg-white p-3 shadow-lg shadow-indigo-100/60 sm:p-4">
                    <div class="booking-progress-scroll">
                    <ol class="booking-progress-steps {{ count($steps) === 1 ? 'booking-progress-steps--single' : '' }} gap-2" role="list" style="--booking-step-count: {{ count($steps) }}; --booking-progress-min-width: {{ count($steps) * 120 }}px">
                        @foreach($steps as $item)
                            @php
                                $isCurrent = $step === $item['number'];
                                $isComplete = $step > $item['number'];
                            @endphp
                            <li>
                                <div
                                    @if($isCurrent) aria-current="step" @endif
                                    class="flex min-h-20 min-w-0 flex-col items-center justify-center gap-1.5 rounded-2xl border px-3 py-2.5 text-center transition
                                        {{ $isCurrent ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100 shadow-sm shadow-indigo-100' : '' }}
                                        {{ $isComplete ? 'border-emerald-300/30 bg-emerald-400/10 text-emerald-100' : '' }}
                                        {{ ! $isCurrent && ! $isComplete ? 'border-white/10 bg-white/[0.04] text-slate-400' : '' }}"
                                >
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold
                                        {{ $isCurrent ? 'bg-indigo-500 text-white' : '' }}
                                        {{ $isComplete ? 'bg-emerald-500 text-white' : '' }}
                                        {{ ! $isCurrent && ! $isComplete ? 'bg-white/10 text-slate-300' : '' }}">
                                        {{ $isComplete ? 'OK' : $item['number'] }}
                                    </span>
                                    <span class="max-w-full whitespace-nowrap text-xs font-black leading-4 sm:text-sm">{{ $item['label'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    </div>
                </nav>

                <section class="booking-step-panel mt-4 rounded-3xl border border-indigo-100 bg-white p-5 shadow-xl shadow-indigo-100/50 sm:p-7">
                    <p class="sr-only" aria-live="polite">Step {{ $step }} of {{ count($steps) }}: {{ $steps[$step - 1]['label'] }}</p>

                    @if($banner)
                        <x-ui.alert type="error" class="booking-error-alert mb-6">
                            <p class="font-black">We couldn't continue</p>
                            <p class="mt-1 leading-6">{{ $banner }}</p>
                        </x-ui.alert>
                    @endif

                    @if($currentPhase === 'mode')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a session type</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Pick Free Demo to try an instructor once, or Paid Lesson to continue learning. You may take one free demo with each instructor.</p>

                            @if(empty($types))
                                <x-ui.empty-state title="No session types available" description="Please check back soon." class="mt-6" />
                            @else
                                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    @foreach($types as $typeOption)
                                        <button
                                            type="button"
                                            wire:click="selectMode(@js($typeOption['key']))"
                                            aria-pressed="{{ $type === $typeOption['key'] ? 'true' : 'false' }}"
                                            class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                {{ $type === $typeOption['key'] ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                        >
                                            <span class="block text-lg font-bold text-white">{{ $typeOption['name'] }}</span>
                                            @if($typeOption['description'])
                                                <span class="mt-1 block text-sm text-slate-400">{{ $typeOption['description'] }}</span>
                                            @endif
                                            <span class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide {{ $typeOption['is_paid'] ? 'bg-fuchsia-500/15 text-fuchsia-200' : 'bg-emerald-500/15 text-emerald-200' }}">
                                                {{ $typeOption['is_paid'] ? 'Paid' : 'Free' }}
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($currentPhase === 'level')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a {{ $levelTermSingular }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Pick the {{ \Illuminate\Support\Str::lower($levelTermSingular) }} that matches the student.</p>

                            @if(empty($levels))
                                <x-ui.empty-state title="Not configured yet" description="Levels are not currently configured for this education system." class="mt-6" />
                            @else
                                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach($levels as $levelOption)
                                        <button
                                            type="button"
                                            wire:click="selectLevel(@js($levelOption['id']))"
                                            aria-pressed="{{ $educationSystemLevelId === $levelOption['id'] ? 'true' : 'false' }}"
                                            class="min-h-20 rounded-2xl border px-4 py-3 text-center transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                {{ $educationSystemLevelId === $levelOption['id'] ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                        >
                                            <span class="block text-lg font-bold text-white">{{ $levelOption['display_label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($currentPhase === 'academic_subject')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a subject</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Start with the area where the student needs support.</p>

                            @if(empty($academicSubjects))
                                <x-ui.empty-state title="No subjects available" description="Please choose a different academic level, or check back soon." class="mt-6" />
                            @else
                                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach($academicSubjects as $subjectOption)
                                        <button
                                            type="button"
                                            wire:click="selectAcademicSubject(@js($subjectOption['id']))"
                                            aria-pressed="{{ $academicSubjectId === $subjectOption['id'] ? 'true' : 'false' }}"
                                            class="min-h-20 rounded-2xl border px-4 py-3 text-center text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                {{ $academicSubjectId === $subjectOption['id'] ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                        >
                                            {{ $subjectOption['name'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($currentPhase === 'curriculum')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Choose a curriculum</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">The curriculum determines which instructors can teach this session.</p>

                            @if(empty($curricula))
                                <x-ui.empty-state title="No curricula available" description="No published curriculum (or eligible instructor) is currently available for this selection. Please choose a different subject, or check back soon." class="mt-6" />
                            @else
                                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    @foreach($curricula as $curriculumOption)
                                        <button
                                            type="button"
                                            wire:click="selectCurriculum(@js($curriculumOption['id']))"
                                            aria-pressed="{{ $curriculumId === $curriculumOption['id'] ? 'true' : 'false' }}"
                                            class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                                {{ $curriculumId === $curriculumOption['id'] ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                        >
                                            <span class="block text-lg font-bold text-white">{{ $curriculumOption['name'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($currentPhase === 'subject')
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

                    @if($currentPhase === 'grade')
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

                    @if($currentPhase === 'billing_mode')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Single session or recurring?</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Recurring sessions repeat with the same instructor at the same time.</p>

                            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <button
                                    type="button"
                                    wire:click="selectBillingMode('single')"
                                    aria-pressed="{{ ! $recurring ? 'true' : 'false' }}"
                                    class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                        {{ ! $recurring ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                >
                                    <span class="block text-lg font-bold text-white">Single session</span>
                                    <span class="mt-1 block text-sm text-slate-400">Book one lesson at a time you choose.</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="selectBillingMode('recurring')"
                                    aria-pressed="{{ $recurring ? 'true' : 'false' }}"
                                    class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30
                                        {{ $recurring ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10' }}"
                                >
                                    <span class="block text-lg font-bold text-white">Recurring sessions</span>
                                    <span class="mt-1 block text-sm text-slate-400">Repeat this lesson daily or weekly.</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    @if($currentPhase === 'frequency')
                        <div
                            x-data="{ frequency: @entangle('frequency').live, occurrences: @entangle('occurrences').live }"
                        >
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">How often?</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">Choose the pattern and how many sessions to book (up to {{ \App\Booking\DTOs\RecurrenceData::MAX_OCCURRENCES }}).</p>

                            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <button type="button" @click="frequency = 'daily'" aria-pressed="{{ $frequency === 'daily' ? 'true' : 'false' }}"
                                    class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30"
                                    :class="frequency === 'daily' ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10'">
                                    <span class="block text-lg font-bold text-white">Daily</span>
                                </button>
                                <button type="button" @click="frequency = 'weekly'" aria-pressed="{{ $frequency === 'weekly' ? 'true' : 'false' }}"
                                    class="rounded-2xl border p-5 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30"
                                    :class="frequency === 'weekly' ? 'border-indigo-300/60 bg-indigo-400/10 text-indigo-100' : 'border-white/10 bg-slate-900/70 text-slate-200 hover:border-indigo-300/30 hover:bg-indigo-400/10'">
                                    <span class="block text-lg font-bold text-white">Weekly</span>
                                </button>
                            </div>

                            <div class="mt-6">
                                <label for="occurrences" class="mb-1.5 block text-sm font-medium text-slate-200">Number of sessions</label>
                                <input id="occurrences" type="number" min="2" max="{{ \App\Booking\DTOs\RecurrenceData::MAX_OCCURRENCES }}" x-model.number="occurrences" class="block w-32 rounded-xl border border-white/10 bg-slate-900/70 px-3.5 py-2 text-sm text-slate-100 shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20">
                            </div>

                            <div class="mt-6">
                                <x-ui.button type="button" @click="$wire.selectFrequency(frequency, occurrences)" x-bind:disabled="!frequency">
                                    Continue
                                </x-ui.button>
                            </div>
                        </div>
                    @endif

                    @if($currentPhase === 'date')
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

                            <div wire:loading.flex wire:target="selectGrade,selectCurriculum,previousMonth,nextMonth" class="mt-6 items-center justify-center gap-3 text-sm text-slate-400">
                                <x-ui.spinner size="sm" />
                                Checking availability...
                            </div>

                            <div wire:loading.remove wire:target="selectGrade,selectCurriculum,previousMonth,nextMonth" class="mt-4">
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

                    @if($currentPhase === 'time')
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
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Phase 4D — explicit funding choice. Shown only when the student
                         actually holds a package that qualifies for THIS lesson. "Pay normally"
                         is always offered and nothing is preselected: a package is never
                         auto-applied just because it matches. --}}
                    @if($currentPhase === 'funding')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">How would you like to pay?</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                You have a package that covers this lesson. Use it, or pay for this lesson on its own.
                            </p>

                            <div class="mt-6 space-y-3">
                                @foreach($fundingOptions as $option)
                                    <button type="button" wire:click="selectFunding('{{ $option['id'] }}')"
                                        class="block w-full rounded-2xl border border-white/10 bg-slate-900/70 p-4 text-left transition hover:border-indigo-400/50 hover:bg-slate-900">
                                        <span class="block font-bold text-white">Use package — {{ $option['name'] }}</span>
                                        <span class="mt-1 block text-sm text-slate-400">
                                            {{ $option['subject_name'] }}@if($option['level_display']) · {{ $option['level_display'] }}@endif
                                        </span>
                                        <span class="mt-2 block text-sm font-semibold text-emerald-300">
                                            {{ $option['available_to_book'] }} {{ \Illuminate\Support\Str::plural('lesson', $option['available_to_book']) }} available to book
                                        </span>
                                        @if($option['scheduled'] > 0)
                                            <span class="block text-xs text-slate-500">{{ $option['scheduled'] }} already scheduled</span>
                                        @endif
                                        @if($option['expires_at'])
                                            <span class="mt-1 block text-xs text-slate-500">
                                                Valid until {{ \Carbon\CarbonImmutable::parse($option['expires_at'])->timezone($timezone)->format('j F Y') }}
                                            </span>
                                        @endif
                                    </button>
                                @endforeach

                                <button type="button" wire:click="selectFunding('')"
                                    class="block w-full rounded-2xl border border-white/10 bg-slate-900/70 p-4 text-left transition hover:border-indigo-400/50 hover:bg-slate-900">
                                    <span class="block font-bold text-white">Pay for this lesson</span>
                                    <span class="mt-1 block text-sm text-slate-400">Keep your package for another time.</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    @if($currentPhase === 'review')
                        <div>
                            <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black text-white outline-none">Review your booking</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                @if($selectedType['is_paid'] ?? false)
                                    Double-check everything below, then continue to secure payment. Your session is confirmed only after payment succeeds.
                                @else
                                    Double-check everything below, add any notes, then confirm the session.
                                @endif
                            </p>

                            <dl class="mt-6 grid gap-3 rounded-2xl bg-slate-900/70 p-4 text-sm ring-1 ring-white/10 sm:grid-cols-2">
                                @if($selectedType)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Session</dt><dd class="mt-1 font-semibold text-white">{{ $selectedType['name'] }}</dd></div>
                                @endif
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject</dt><dd class="mt-1 font-semibold capitalize text-white">{{ str_replace(['_', '-'], ' ', (string) $subject) }}</dd></div>
                                @if($academicFlowActive)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $levelTermSingular }}</dt><dd class="mt-1 font-semibold text-white">{{ collect($levels)->firstWhere('id', $educationSystemLevelId)['display_label'] ?? $grade }}</dd></div>
                                @else
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Grade</dt><dd class="mt-1 font-semibold text-white">{{ $grade }}</dd></div>
                                @endif
                                @if($lockedInstructorName)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instructor</dt><dd class="mt-1 font-semibold text-white">{{ $lockedInstructorName }}</dd></div>
                                @endif
                                @if($recurring)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recurrence</dt><dd class="mt-1 font-semibold capitalize text-white">{{ $frequency }}, {{ $occurrences }} sessions</dd></div>
                                @endif
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $recurring ? 'First date' : 'Date' }}</dt><dd class="mt-1 font-semibold text-white">{{ $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '' }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Time</dt><dd class="mt-1 font-semibold text-white">{{ $selectedSlotStartsAt ? \Carbon\CarbonImmutable::parse($selectedSlotStartsAt)->timezone($timezone)->format('g:i A') : '' }}</dd></div>
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Student</dt><dd class="mt-1 font-semibold text-white">{{ auth()->user()?->name }}</dd></div>
                                {{-- A package lesson is PREPAID, never "free" (§30). --}}
                                @if($packageEntitlementId)
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payment</dt><dd class="mt-1 font-semibold text-emerald-300">Covered by package</dd></div>
                                @endif
                            </dl>

                            <div class="mt-6">
                                <label for="booking-notes" class="mb-1.5 block text-sm font-medium text-slate-200">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                                <textarea id="booking-notes" wire:model.blur="notes" rows="4" placeholder="Anything the instructor should know before the session" class="block w-full rounded-xl border border-white/10 bg-slate-900/70 px-3.5 py-2 text-sm text-slate-100 shadow-sm transition placeholder:text-slate-500 focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-400/20"></textarea>
                                @error('notes') <p class="mt-1.5 text-xs font-medium text-red-300">{{ $message }}</p> @enderror
                            </div>

                            <div class="mt-6 flex flex-wrap gap-3">
                                <x-ui.button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                                    <span wire:loading.remove wire:target="submit">{{ ($selectedType['is_paid'] ?? false) ? 'Continue to payment' : 'Confirm booking' }}</span>
                                    <span wire:loading wire:target="submit">{{ ($selectedType['is_paid'] ?? false) ? 'Reserving your slot...' : 'Booking...' }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    @endif

                    @if($currentPhase === 'confirmed' && $result && ($result['recurring'] ?? false))
                        <div class="text-center">
                            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full {{ $result['requires_payment'] ? 'bg-amber-400/15 text-amber-600' : 'bg-emerald-400/15 text-emerald-200' }}">
                                @if($result['requires_payment'])
                                    <span class="text-2xl font-black" aria-hidden="true">$</span>
                                @else
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                @endif
                            </span>
                            <h2 data-booking-step-title tabindex="-1" class="mt-4 text-2xl font-black text-white outline-none">
                                @if($result['requires_payment'])
                                    {{ count($result['bookings']) }} sessions reserved pending payment
                                @else
                                    {{ count($result['bookings']) }} of {{ count($result['bookings']) + count($result['failures']) }} sessions confirmed
                                @endif
                            </h2>
                            <p class="mt-2 text-sm text-slate-400">
                                {{ $result['requires_payment'] ? 'Complete payment for each reserved session from My Bookings before it is confirmed.' : 'We have sent a confirmation to '.auth()->user()?->email.'.' }}
                            </p>

                            <dl class="mx-auto mt-6 max-w-md space-y-3 rounded-2xl bg-slate-900/70 p-5 text-left text-sm ring-1 ring-white/10">
                                @foreach($result['bookings'] as $occurrence)
                                    <div class="flex justify-between gap-4 border-b border-white/5 pb-3 last:border-0 last:pb-0">
                                        <dt class="text-slate-400">{{ viewer_datetime($occurrence['starts_at']) }}</dt>
                                        <dd class="font-semibold text-white">{{ $occurrence['payment_status'] === 'paid' ? 'Paid' : ($occurrence['requires_payment'] ? 'Payment due' : $occurrence['status_label']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if(! empty($result['failures']))
                                <div class="mx-auto mt-4 max-w-md rounded-2xl border border-amber-300/20 bg-amber-400/10 p-4 text-left text-xs text-amber-200">
                                    <p class="font-bold uppercase tracking-wide">Some sessions could not be booked</p>
                                    <ul class="mt-2 list-disc space-y-1 pl-4">
                                        @foreach($result['failures'] as $when => $reason)
                                            <li>{{ viewer_datetime($when) }} — {{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($result['requires_payment'])
                                <p class="mx-auto mt-4 max-w-md text-xs text-indigo-200">One or more sessions are reserved pending payment — pay for each from My Bookings.</p>
                            @endif

                            <div class="mt-6 flex flex-wrap justify-center gap-3">
                                <x-ui.button href="{{ $result['my_bookings_url'] }}">View my bookings</x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="restart">Book another session</x-ui.button>
                            </div>
                        </div>
                    @endif

                    @if($currentPhase === 'confirmed' && $result && ! ($result['recurring'] ?? false))
                        @php($isAwaitingPayment = $result['requires_payment'] && $result['payment_status'] !== 'paid')
                        <div class="mx-auto max-w-5xl">
                            <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-4 text-left">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $isAwaitingPayment ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                                @if($isAwaitingPayment)
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                @else
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                @endif
                            </span>
                            <div>
                                <p class="text-xs font-black uppercase tracking-wide {{ $isAwaitingPayment ? 'text-amber-700' : 'text-emerald-700' }}">{{ $isAwaitingPayment ? 'Slot reserved · Payment pending' : 'Confirmed' }}</p>
                                <h2 data-booking-step-title tabindex="-1" class="mt-1 text-2xl font-black text-slate-950 outline-none sm:text-3xl">{{ $isAwaitingPayment ? 'Complete your payment' : 'Booking confirmed' }}</h2>
                                <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-500">{{ $isAwaitingPayment ? 'Your selected time is being held temporarily. Payment confirms the session.' : 'We have sent a confirmation to '.auth()->user()?->email.'.' }}</p>
                            </div>
                            </div>
                            <div class="shrink-0 rounded-xl bg-slate-50 px-3 py-2 text-left ring-1 ring-slate-200 sm:text-right">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Booking reference</p>
                                <p class="mt-0.5 font-mono text-sm font-black text-slate-800">{{ $result['reference'] }}</p>
                            </div>
                            </div>

                            <div class="mt-5 grid gap-5 {{ $isAwaitingPayment ? 'lg:grid-cols-5' : '' }}">

                            <dl class="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-5 text-left text-sm {{ $isAwaitingPayment ? 'lg:col-span-2' : '' }}">
                                <p class="text-xs font-black uppercase tracking-wide text-slate-500">Booking summary</p>
                                <div class="flex items-start justify-between gap-4"><dt class="text-slate-500">Session</dt><dd class="text-right font-semibold text-slate-900">{{ $result['type']['name'] }}</dd></div>
                                @if($result['subject'] ?? null)
                                    <div class="flex items-start justify-between gap-4"><dt class="text-slate-500">Subject</dt><dd class="text-right font-semibold text-slate-900">{{ $result['subject'] }}</dd></div>
                                @endif
                                <div class="flex items-start justify-between gap-4"><dt class="text-slate-500">When</dt><dd class="max-w-[70%] text-right font-semibold leading-5 text-slate-900">{{ viewer_datetime_labelled($result['starts_at']) }}</dd></div>
                                @if($result['amount_formatted'] ?? null)
                                    <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-3"><dt class="font-semibold text-slate-600">Total</dt><dd class="text-lg font-black text-slate-950">{{ $result['amount_formatted'] }}</dd></div>
                                @endif
                            </dl>

                            @if($isAwaitingPayment)
                                <div class="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-5 text-left lg:col-span-3">
                                    <p class="text-xs font-black uppercase tracking-wide text-indigo-700">Complete payment</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Use the secure payment option below to confirm this booking.</p>

                                    @if($paymentBanner)
                                        <div class="booking-payment-error mt-3 rounded-xl border px-4 py-3 text-left" role="alert">
                                            <p class="text-xs font-black uppercase tracking-wide">Payment needs attention</p>
                                            <p class="mt-1 text-sm font-semibold leading-6">{{ $paymentBanner }}</p>
                                        </div>
                                    @endif

                                    <x-ui.button type="button" class="booking-checkout-primary mt-4 w-full" wire:click="initiatePayment" wire:loading.attr="disabled" wire:target="initiatePayment">
                                        <span wire:loading.remove wire:target="initiatePayment">{{ ($result['amount_formatted'] ?? null) ? 'Pay '.$result['amount_formatted'] : 'Pay now' }}</span>
                                        <span wire:loading wire:target="initiatePayment">Preparing payment...</span>
                                    </x-ui.button>

                                    @if($walletOption['available'] ?? false)
                                        <div class="mt-3 rounded-xl border border-white/10 bg-slate-950/40 p-3 text-left">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Pay with wallet</p>
                                            <p class="mt-1 text-xs text-slate-400">Wallet balance: <span class="font-semibold text-white">{{ $walletOption['balance_formatted'] }}</span></p>

                                            @if($walletOption['sufficient'] ?? false)
                                                <x-ui.button type="button" variant="secondary" class="booking-checkout-secondary mt-2 w-full" wire:click="payWithWallet" wire:loading.attr="disabled" wire:target="payWithWallet">
                                                    <span wire:loading.remove wire:target="payWithWallet">Pay from wallet</span>
                                                    <span wire:loading wire:target="payWithWallet">Paying...</span>
                                                </x-ui.button>
                                            @else
                                                <p class="mt-2 text-[11px] text-amber-300">Your wallet balance is not sufficient to pay for this booking.</p>
                                            @endif
                                        </div>
                                    @else
                                        <p class="mt-3 text-xs leading-5 text-slate-500">Wallet payment is unavailable for this booking. You can still use the secure payment method above.</p>
                                    @endif

                                    @if(($paymentOrder['provider'] ?? null) === 'stripe')
                                        {{-- wire:ignore: this subtree is polled by checkPaymentStatus() every
                                             few seconds while confirming — Livewire must never re-morph it, or
                                             the mounted Stripe Elements iframe (DOM Livewire doesn't know about)
                                             would be torn down mid-confirmation. --}}
                                        <div class="mt-3" wire:ignore>
                                            <div id="stripe-payment-element" class="rounded-lg bg-white p-3"></div>
                                            <p id="stripe-payment-errors" class="mt-2 text-xs font-semibold text-rose-300" role="alert"></p>
                                            <x-ui.button type="button" id="stripe-confirm-button" class="booking-checkout-primary mt-3 w-full" disabled>
                                                Confirm card payment
                                            </x-ui.button>
                                        </div>
                                    @endif

                                    @if(($paymentOrder['provider'] ?? null) === 'fake' && app()->environment(['local', 'testing']))
                                        <div class="mt-4 rounded-xl border border-dashed border-amber-300 bg-amber-50 p-3">
                                            <p class="text-[11px] font-black uppercase tracking-wide text-amber-700">Developer test controls · Fake provider</p>
                                            <p class="mt-1 text-xs text-amber-800/80">Visible only in local and testing environments.</p>
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <x-ui.button type="button" size="sm" class="booking-checkout-test-success" wire:click="simulateFakePayment(true)" wire:loading.attr="disabled">Simulate success</x-ui.button>
                                                <x-ui.button type="button" size="sm" variant="secondary" class="booking-checkout-test-failure" wire:click="simulateFakePayment(false)" wire:loading.attr="disabled">Simulate failure</x-ui.button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                            </div>

                            <div class="booking-checkout-actions mt-6 flex flex-wrap gap-3">
                                <x-ui.button href="{{ $result['my_bookings_url'] }}" variant="{{ $isAwaitingPayment ? 'secondary' : 'primary' }}" class="{{ $isAwaitingPayment ? 'booking-checkout-secondary' : 'booking-checkout-primary' }}">View my bookings</x-ui.button>
                                <x-ui.button type="button" variant="secondary" class="booking-checkout-secondary" wire:click="restart">Book another session</x-ui.button>
                            </div>
                        </div>
                    @endif

                    @if(! in_array($currentPhase, ['mode', 'confirmed']))
                        <div class="mt-8 border-t border-white/10 pt-5">
                            <button type="button" wire:click="back" class="text-sm font-semibold text-slate-400 transition hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/30">
                                &larr; Back
                            </button>
                        </div>
                    @endif
                </section>
            </div>

            @if(! in_array($currentPhase, ['mode', 'confirmed']))
                <aside class="lg:col-span-1">
                    <div class="booking-summary-card rounded-3xl border border-indigo-100 bg-white p-5 shadow-2xl shadow-indigo-100/60 sm:p-6 lg:sticky lg:top-28">
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
                                <dt class="text-slate-400">{{ $academicFlowActive ? $levelTermSingular : 'Grade' }}</dt>
                                <dd class="text-right font-semibold text-white">{{ $academicFlowActive ? (collect($levels)->firstWhere('id', $educationSystemLevelId)['display_label'] ?? '-') : ($grade ?? '-') }}</dd>
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

@once
    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const page = document.querySelector('[data-booking-wizard-page]');
        if (! page || window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) return;

        page.classList.add('booking-motion-ready');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -6% 0px', threshold: 0.1 });

        page.querySelectorAll('[data-booking-reveal]').forEach((element, index) => {
            element.style.setProperty('--booking-delay', `${index * 100}ms`);
            observer.observe(element);
        });
    });
    </script>
    @endpush
@endonce

@script
@include('livewire.frontend.booking.partials.razorpay-checkout-script')
@include('livewire.frontend.booking.partials.stripe-checkout-script')
@endscript
