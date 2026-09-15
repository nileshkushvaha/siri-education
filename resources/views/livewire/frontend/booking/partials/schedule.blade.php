@php
    $isPaid = (bool) ($selectedType['is_paid'] ?? false);
    $showCalendar = in_array($currentPhase, ['date', 'time'], true);
    // A repeating schedule shares ONE time across every class, so the
    // times shown are the first class's — the start date may not be a
    // class day at all.
    $timesDate = $recurring ? $firstClassDate : $date;
    $showTimes = $currentPhase === 'time' && $timesDate !== null;
    $selectedDay = $timesDate ? \Carbon\CarbonImmutable::parse($timesDate, $timezone) : null;
    $durationMinutes = (int) ($selectedType['duration_minutes'] ?? 0);
    $tzCity = str_replace('_', ' ', \Illuminate\Support\Str::afterLast($timezone, '/'));
    $tzOffset = 'GMT'.\Carbon\CarbonImmutable::now($timezone)->format('P');
    $calendarTargets = 'selectInstructor,selectBillingMode,toggleWeekday,setEndCondition,setOccurrences,setEndDate,previousMonth,nextMonth,continueStage,editStage,editPhase';
    $askInstructor = $isPaid && $lockedInstructorId === null;
    $instructorGroups = [
        ['key' => 'previous', 'title' => 'Book again', 'hint' => 'Instructors you have learned this subject with, most recent first.'],
        ['key' => 'favourites', 'title' => 'Your favourites', 'hint' => null],
        ['key' => 'others', 'title' => 'More instructors', 'hint' => null],
    ];
    $hasInstructorOptions = collect($instructorOptions)->flatten(1)->isNotEmpty();
@endphp

<div class="space-y-5">
    <div>
        <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black tracking-tight text-fg-strong outline-none">Choose your schedule</h2>
        <p class="mt-1.5 text-sm leading-6 text-fg-muted">
            Times are shown in your local time zone, <span class="font-semibold text-fg">{{ $tzCity }} ({{ $tzOffset }})</span>.
            Not right? <a href="{{ route('profile.show') }}#timezone" class="font-semibold text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-700 dark:text-indigo-300">Change it in your profile</a>.
        </p>
    </div>

    @if($askInstructor)
        {{-- Who to learn with. Paid lessons only; a profile deep-link locks
             the instructor and skips this. Continuity first: the instructors
             this student already learned the subject with lead the list. --}}
        @if($currentPhase === 'instructor')
            <section aria-labelledby="booking-instructor" data-booking-phase="instructor">
                <h3 id="booking-instructor" class="text-lg font-black text-fg-strong">Who would you like to learn with?</h3>
                <p class="mt-1 text-sm text-fg-muted">Every instructor here teaches this subject at your level. Choose one, or let us match you with whoever is available.</p>

                <div class="mt-4 space-y-5">
                    @foreach($instructorGroups as $group)
                        @php $cards = $instructorOptions[$group['key']] ?? []; @endphp
                        @continue($cards === [])
                        <div data-instructor-group="{{ $group['key'] }}">
                            <div class="flex items-baseline gap-2">
                                <h4 class="text-sm font-black uppercase tracking-wide text-fg-muted">{{ $group['title'] }}</h4>
                                @if($group['hint'])<span class="text-xs text-fg-faint">{{ $group['hint'] }}</span>@endif
                            </div>
                            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach($cards as $card)
                                    <button type="button"
                                            wire:click="selectInstructor({{ (int) $card['id'] }})"
                                            aria-pressed="{{ $instructorChosen && $instructorId === (int) $card['id'] ? 'true' : 'false' }}"
                                            data-instructor-option="{{ $card['id'] }}"
                                            class="booking-option group relative flex w-full items-start gap-3 rounded-2xl border-2 p-3 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 {{ $instructorChosen && $instructorId === (int) $card['id'] ? 'border-indigo-500 bg-indigo-500/10 shadow-sm shadow-indigo-500/10' : 'border-edge/60 bg-surface-raised hover:border-indigo-300 hover:bg-indigo-500/5' }}">
                                        <x-ui.avatar :src="$card['avatar_url'] ?? null" :name="$card['name']" size="md" class="shrink-0" />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-bold text-fg-strong">{{ $card['name'] }}</span>
                                                @if(($card['badge'] ?? '') === 'previous')
                                                    <x-ui.badge color="indigo">{{ $loop->parent->first && $loop->first ? 'Last time' : 'Booked before' }}</x-ui.badge>
                                                @elseif(($card['badge'] ?? '') === 'favourite')
                                                    <x-ui.badge color="success">Favourite</x-ui.badge>
                                                @endif
                                            </span>
                                            @if(! empty($card['headline']))
                                                <span class="mt-0.5 block truncate text-xs text-fg-muted">{{ $card['headline'] }}</span>
                                            @endif
                                            <span class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-faint">
                                                @if(($card['ratings']['count'] ?? 0) > 0)
                                                    <span><span class="font-semibold text-fg-strong">★ {{ number_format((float) $card['ratings']['average'], 1) }}</span> ({{ $card['ratings']['count'] }})</span>
                                                @endif
                                                @if(! empty($card['years_experience']))
                                                    <span>{{ $card['years_experience'] }}+ yrs experience</span>
                                                @endif
                                                @if(! empty($card['subjects']))
                                                    <span>{{ implode(' · ', $card['subjects']) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div data-instructor-group="any">
                        <x-booking.option-card
                            wire:click="selectInstructor(null)"
                            :selected="$instructorChosen && $instructorId === null"
                            title="Any available instructor"
                            :description="$hasInstructorOptions ? 'We match you with an available instructor when you confirm — your previous instructor for this subject whenever they are free.' : 'No instructor is listed for this subject right now; we will match you with whoever is available when you confirm.'"
                        />
                    </div>
                </div>
            </section>
        @elseif($instructorChosen)
            <x-booking.chosen-row label="Instructor" :value="$instructorName ?? 'Any available instructor'" phase="instructor" />
        @endif
    @endif

    @if($isPaid && (! $askInstructor || $instructorChosen))
        <section aria-labelledby="booking-how-often">
            <h3 id="booking-how-often" class="text-lg font-black text-fg-strong">How often would you like to study?</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-booking.option-card
                    wire:click="selectBillingMode('single')"
                    :selected="$billingModeChosen && ! $recurring"
                    title="One-time class"
                    description="Book a single class."
                />
                <x-booking.option-card
                    wire:click="selectBillingMode('recurring')"
                    :selected="$recurring"
                    title="Repeating classes"
                    description="Same time each week, on the days you choose, with one instructor."
                />
            </div>
        </section>

        @if($recurring)
            {{--
                Everything a repeating schedule needs, in this one step.
                Days first, because they decide which start dates mean
                anything and which times get loaded.
            --}}
            <section aria-labelledby="booking-class-days" class="rounded-2xl border border-edge bg-surface p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <h3 id="booking-class-days" class="text-base font-black text-fg-strong">Class days</h3>
                    @if($perWeekLabel)
                        <p class="text-sm font-bold text-indigo-700 dark:text-indigo-300" aria-live="polite">{{ $perWeekLabel }}</p>
                    @endif
                </div>
                <p class="mt-1 text-sm text-fg-muted">Your classes repeat on these days every week. Pick all seven for daily classes.</p>

                <div class="mt-3 grid grid-cols-7 gap-1.5" role="group" aria-labelledby="booking-class-days">
                    @foreach([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'] as $value => $name)
                        @php $isOn = in_array($value, $selectedWeekdays, true); @endphp
                        <button
                            type="button"
                            wire:click="toggleWeekday({{ $value }})"
                            aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                            aria-label="{{ $name }}"
                            class="flex min-h-12 items-center justify-center rounded-xl border-2 text-sm font-black transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50
                                {{ $isOn
                                    ? 'border-indigo-500 bg-indigo-600 text-white shadow-sm shadow-indigo-500/25'
                                    : 'border-edge bg-surface-raised text-fg hover:border-indigo-300 hover:bg-surface-hover' }}"
                        >
                            <span aria-hidden="true">{{ \Illuminate\Support\Str::substr($name, 0, 3) }}</span>
                        </button>
                    @endforeach
                </div>
                @error('weekdays') <p class="mt-2 text-sm font-semibold text-rose-600 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror
            </section>

        @endif
    @endif

    @if($showCalendar)
        <section aria-labelledby="booking-date">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 id="booking-date" class="text-lg font-black text-fg-strong">{{ $recurring ? 'Starting from' : 'Choose a date' }}</h3>
                    <p class="mt-1 text-sm text-fg-muted">
                        {{ $recurring ? 'Your classes begin on the first chosen day on or after this date.' : 'Highlighted days have open times.' }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" wire:click="previousMonth" @disabled(! $canGoPreviousMonth) aria-label="Previous month" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-edge text-fg transition hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed disabled:opacity-40">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                    </button>
                    <span class="min-w-[7.5rem] text-center text-sm font-bold text-fg-strong" aria-live="polite">{{ \Carbon\CarbonImmutable::parse($month.'-01')->format('F Y') }}</span>
                    <button type="button" wire:click="nextMonth" @disabled(! $canGoNextMonth) aria-label="Next month" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-edge text-fg transition hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed disabled:opacity-40">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                </div>
            </div>

            <div wire:loading.flex wire:target="{{ $calendarTargets }}" class="mt-6 min-h-40 items-center justify-center gap-3 text-sm text-fg-muted" role="status">
                <x-ui.spinner size="sm" />
                Checking availability…
            </div>

            <div wire:loading.remove wire:target="{{ $calendarTargets }}" class="mt-4">
                <div class="grid grid-cols-7 text-center text-[11px] font-bold uppercase tracking-wide text-fg-faint" aria-hidden="true">
                    @foreach(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $day)
                        <span class="py-1.5">{{ $day }}</span>
                    @endforeach
                </div>
                <div class="grid grid-cols-7 gap-1" role="group" aria-label="Choose a date">
                    @foreach($calendar as $cell)
                        <div class="aspect-square min-h-10">
                            @if($cell)
                                <button
                                    type="button"
                                    wire:click="selectDate({{ \Illuminate\Support\Js::from($cell['iso']) }})"
                                    @disabled(! $cell['available'])
                                    aria-label="{{ $cell['label'] }}@if($recurring){{ $cell['is_first_class'] ? ', your first class' : ($cell['is_class_day'] ? ', a class day' : '') }}@else{{ $cell['available'] ? ', available' : ', unavailable' }}@endif"
                                    aria-pressed="{{ $cell['selected'] ? 'true' : 'false' }}"
                                    class="relative h-full w-full rounded-xl text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed
                                        {{ $cell['selected'] ? 'bg-indigo-600 text-white shadow-md shadow-indigo-500/25' : '' }}
                                        {{ ! $cell['selected'] && $cell['is_class_day'] ? 'bg-indigo-500/10 text-indigo-700 ring-1 ring-inset ring-indigo-400/40 hover:bg-indigo-500/20 dark:text-indigo-200' : '' }}
                                        {{ ! $cell['selected'] && ! $cell['is_class_day'] && $cell['available'] && ! $recurring ? 'bg-indigo-500/10 text-indigo-700 ring-1 ring-inset ring-indigo-400/40 hover:bg-indigo-500/20 dark:text-indigo-200' : '' }}
                                        {{ ! $cell['selected'] && ! $cell['is_class_day'] && $cell['available'] && $recurring ? 'text-fg hover:bg-surface-hover' : '' }}
                                        {{ ! $cell['available'] ? 'text-fg-faint opacity-50' : '' }}"
                                >
                                    {{ $cell['day'] }}
                                    {{-- The one day that is actually the first class, called out on the grid itself. --}}
                                    @if($cell['is_first_class'] && ! $cell['selected'])
                                        <span class="absolute inset-x-0 bottom-1 mx-auto h-1 w-1 rounded-full bg-indigo-500" aria-hidden="true"></span>
                                    @endif
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if($recurring && $firstClassDate)
                    <p class="mt-4 rounded-xl bg-indigo-500/10 px-3.5 py-2.5 text-sm font-semibold text-indigo-900 dark:text-indigo-200" aria-live="polite">
                        First class: {{ \Carbon\CarbonImmutable::parse($firstClassDate)->format('l, j F Y') }}
                    </p>
                @elseif($recurring && $date && ! $firstClassDate)
                    <p class="mt-4 rounded-xl border border-dashed border-edge-strong px-3.5 py-2.5 text-sm text-fg-muted" role="status">
                        Choose the days above to see when your first class falls.
                    </p>
                @elseif(! $recurring && empty($dates))
                    <div class="mt-4 rounded-2xl border border-dashed border-edge-strong px-4 py-5 text-center">
                        <p class="text-sm font-semibold text-fg-strong">{{ $instructorName ? $instructorName.' has no times available this month.' : 'No times are available this month.' }}</p>
                        <p class="mt-1 text-sm text-fg-muted">
                            {{ $canGoNextMonth ? 'Try the next month.' : 'Please check back soon.' }}
                            @if($askInstructor && $instructorId !== null)
                                Or <button type="button" wire:click="editPhase('instructor')" class="font-semibold text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-700 dark:text-indigo-300">choose a different instructor</button>.
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if($showTimes)
        <section aria-labelledby="booking-time">
            <h3 id="booking-time" class="text-lg font-black text-fg-strong">{{ $recurring ? 'Class time' : 'Available times' }}</h3>
            <p class="mt-1 text-sm text-fg-muted">
                @if($recurring)
                    Every class uses this time. Shown for your first class, {{ $selectedDay?->format('l, j F') }}@if($durationMinutes) · {{ $durationMinutes }} minutes @endif · {{ $tzCity }} ({{ $tzOffset }}).
                @else
                    {{ $selectedDay?->format('l, j F') }}@if($durationMinutes) · {{ $durationMinutes }} minutes @endif
                @endif
            </p>

            <div wire:loading.flex wire:target="selectDate,toggleWeekday" class="mt-5 min-h-24 items-center justify-center gap-3 text-sm text-fg-muted" role="status">
                <x-ui.spinner size="sm" />
                Loading times…
            </div>

            <div wire:loading.remove wire:target="selectDate,toggleWeekday" class="mt-4 space-y-5">
                @if(empty($slotGroups))
                    <div class="rounded-2xl border border-dashed border-edge-strong px-4 py-5 text-center">
                        <p class="text-sm font-semibold text-fg-strong">{{ $instructorName ? $instructorName.' has no times available on this date.' : 'No times are available on this date.' }}</p>
                        <p class="mt-1 text-sm text-fg-muted">
                            {{ $recurring ? 'Try a different start date, or change your class days.' : 'Try another date.' }}
                            @if($askInstructor && $instructorId !== null)
                                Or <button type="button" wire:click="editPhase('instructor')" class="font-semibold text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-700 dark:text-indigo-300">choose a different instructor</button>.
                            @endif
                        </p>
                    </div>
                @else
                    @foreach($slotGroups as $group)
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">{{ $group['label'] }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($group['slots'] as $slotOption)
                                    <x-booking.option-card
                                        wire:click="selectSlot({{ \Illuminate\Support\Js::from($slotOption['starts_at']) }})"
                                        :selected="$selectedSlotStartsAt === $slotOption['starts_at']"
                                        :title="$slotOption['label']"
                                        align="center"
                                        size="sm"
                                        aria-label="{{ $slotOption['label'] }} to {{ $slotOption['ends_label'] }}"
                                    />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

        </section>
    @endif

    {{--
        "How long?" comes last on purpose: a student settles the routine
        — which days, starting when, at what time — before deciding how
        long to keep it up.
    --}}
    @if($recurring)
            <section aria-labelledby="booking-how-long" class="rounded-2xl border border-edge bg-surface p-4">
                <h3 id="booking-how-long" class="text-base font-black text-fg-strong">How long?</h3>

                @php
                    // "Until I cancel" is only a real option where the
                    // background pass that keeps such a schedule alive is
                    // known to be running. Hidden AND refused server-side
                    // rather than offered and then quietly not honoured.
                    $endOptions = [
                        'after_count' => ['Number of classes', 'Stop after a set number.'],
                        'on_date' => ['Until a date', 'Stop on a date you choose.'],
                    ];

                    if ($ongoingAvailable) {
                        $endOptions['never'] = ['Until I cancel', 'Keeps going until you stop it.'];
                    }
                @endphp

                <div class="mt-3 grid gap-2 sm:grid-cols-{{ count($endOptions) }}" role="group" aria-labelledby="booking-how-long">
                    @foreach($endOptions as $value => [$label, $hint])
                        <button
                            type="button"
                            wire:click="setEndCondition('{{ $value }}')"
                            aria-pressed="{{ $endCondition === $value ? 'true' : 'false' }}"
                            class="min-h-12 rounded-xl border-2 px-3 py-2 text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50
                                {{ $endCondition === $value ? 'border-indigo-500 bg-indigo-500/10' : 'border-edge bg-surface-raised hover:border-indigo-300' }}"
                        >
                            <span class="block text-sm font-bold text-fg-strong">{{ $label }}</span>
                            <span class="block text-xs text-fg-muted">{{ $hint }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="mt-3">
                    @if($endCondition === 'after_count')
                        <label for="occurrences" class="block text-sm font-semibold text-fg">How many classes?</label>
                        <input
                            id="occurrences"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            step="1"
                            value="{{ $occurrences }}"
                            wire:change="setOccurrences($event.target.value)"
                            class="mt-1.5 min-h-11 w-28 rounded-xl border-2 border-edge bg-surface-raised px-3 text-center text-base font-bold text-fg-strong focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-300/30"
                        >
                        @error('occurrences') <p class="mt-1.5 text-sm font-semibold text-rose-600 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror
                    @elseif($endCondition === 'on_date')
                        <label for="end-date" class="block text-sm font-semibold text-fg">Last possible date</label>
                        <input
                            id="end-date"
                            type="date"
                            min="{{ now($timezone)->toDateString() }}"
                            value="{{ $endDate }}"
                            wire:change="setEndDate($event.target.value)"
                            class="mt-1.5 min-h-11 rounded-xl border-2 border-edge bg-surface-raised px-3 text-base font-bold text-fg-strong focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-300/30"
                        >
                        @error('endDate') <p class="mt-1.5 text-sm font-semibold text-rose-600 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror
                    @else
                        <p class="rounded-xl bg-indigo-500/10 px-3.5 py-2.5 text-sm leading-6 text-indigo-900 dark:text-indigo-200">
                            Your classes keep going with no end date. We book them a stretch at a time and add the next ones automatically, so you are only ever charged for classes that have been booked. You can stop the schedule at any point from My Bookings — the usual cancellation notice still applies to each individual class.
                        </p>
                    @endif
                </div>
            </section>
    @endif

    @if($recurring && $selectedSlotStartsAt)
        @include('livewire.frontend.booking.partials.schedule-preview')
    @endif
</div>
