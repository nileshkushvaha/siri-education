@php
    $isPaid = (bool) ($selectedType['is_paid'] ?? false);
    $showCalendar = in_array($currentPhase, ['date', 'time'], true);
    $showTimes = $currentPhase === 'time' && $date !== null;
    $selectedDay = $date ? \Carbon\CarbonImmutable::parse($date, $timezone) : null;
    $tzCity = str_replace('_', ' ', \Illuminate\Support\Str::afterLast($timezone, '/'));
    $tzOffset = 'GMT'.\Carbon\CarbonImmutable::now($timezone)->format('P');
    $calendarTargets = 'selectBillingMode,selectFrequency,previousMonth,nextMonth,continueStage,editStage,editPhase';
@endphp

<div class="space-y-6">
    <div>
        <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black tracking-tight text-fg-strong outline-none">Choose your schedule</h2>
        <p class="mt-1.5 text-sm leading-6 text-fg-muted">
            Times are shown in your local time zone, <span class="font-semibold text-fg">{{ $tzCity }} ({{ $tzOffset }})</span>.
            Not right? <a href="{{ route('profile.show') }}#timezone" class="font-semibold text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-700 dark:text-indigo-300">Change it in your profile</a>.
        </p>
    </div>

    @if($isPaid)
        <section aria-labelledby="booking-how-often">
            <h3 id="booking-how-often" class="text-lg font-black text-fg-strong">How often would you like to study?</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-booking.option-card
                    wire:click="selectBillingMode('single')"
                    :selected="$billingModeChosen && ! $recurring"
                    title="One-time session"
                    description="Book a single class."
                />
                <x-booking.option-card
                    wire:click="selectBillingMode('recurring')"
                    :selected="$recurring"
                    title="Repeating sessions"
                    description="Keep the same time every day or every week, with one instructor."
                />
            </div>
        </section>

        @if($recurring && $currentPhase === 'frequency')
            <section
                aria-labelledby="booking-repeat"
                class="rounded-2xl border border-indigo-300/40 bg-indigo-500/5 p-4 sm:p-5"
                x-data="{ frequency: @entangle('frequency').live, occurrences: @entangle('occurrences').live }"
            >
                <h3 id="booking-repeat" class="text-base font-black text-fg-strong">Set the repeat pattern</h3>
                <p class="mt-1 text-sm text-fg-muted">The date and time you pick next become the first session.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <fieldset>
                        <legend class="text-sm font-semibold text-fg">Repeat</legend>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button type="button" @click="frequency = 'weekly'" :aria-pressed="frequency === 'weekly' ? 'true' : 'false'"
                                class="min-h-11 rounded-xl border-2 px-3 text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50"
                                :class="frequency === 'weekly' ? 'border-indigo-500 bg-indigo-500/10 text-fg-strong' : 'border-edge bg-surface-raised text-fg hover:border-indigo-300'">
                                Weekly
                            </button>
                            <button type="button" @click="frequency = 'daily'" :aria-pressed="frequency === 'daily' ? 'true' : 'false'"
                                class="min-h-11 rounded-xl border-2 px-3 text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50"
                                :class="frequency === 'daily' ? 'border-indigo-500 bg-indigo-500/10 text-fg-strong' : 'border-edge bg-surface-raised text-fg hover:border-indigo-300'">
                                Daily
                            </button>
                        </div>
                    </fieldset>

                    <div>
                        <label for="occurrences" class="block text-sm font-semibold text-fg">Number of sessions</label>
                        <div class="mt-2 flex items-stretch">
                            <button type="button" @click="occurrences = Math.max(2, (parseInt(occurrences) || 2) - 1)" aria-label="One fewer session" class="min-h-11 w-11 rounded-l-xl border-2 border-r-0 border-edge bg-surface-raised text-lg font-bold text-fg hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">−</button>
                            <input id="occurrences" type="number" inputmode="numeric" min="2" max="{{ \App\Booking\DTOs\RecurrenceData::MAX_OCCURRENCES }}" x-model.number="occurrences" class="min-h-11 w-20 border-2 border-edge bg-surface-raised text-center text-base font-bold text-fg-strong focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-300/30">
                            <button type="button" @click="occurrences = Math.min({{ \App\Booking\DTOs\RecurrenceData::MAX_OCCURRENCES }}, (parseInt(occurrences) || 2) + 1)" aria-label="One more session" class="min-h-11 w-11 rounded-r-xl border-2 border-l-0 border-edge bg-surface-raised text-lg font-bold text-fg hover:bg-surface-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50">+</button>
                        </div>
                        <p class="mt-1 text-xs text-fg-faint">Between 2 and {{ \App\Booking\DTOs\RecurrenceData::MAX_OCCURRENCES }} sessions.</p>
                    </div>
                </div>

                <div class="mt-4">
                    <x-ui.button type="button" variant="secondary" @click="$wire.selectFrequency(frequency, occurrences)" x-bind:disabled="!frequency">
                        Continue to date &amp; time
                    </x-ui.button>
                </div>
            </section>
        @elseif($recurring && $frequency)
            <x-booking.chosen-row label="Repeat" :value="ucfirst($frequency).' · '.$occurrences.' sessions'" phase="frequency" />
        @endif
    @endif

    @if($showCalendar)
        <section aria-labelledby="booking-date">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 id="booking-date" class="text-lg font-black text-fg-strong">{{ $recurring ? 'Choose the first date' : 'Choose a date' }}</h3>
                    <p class="mt-1 text-sm text-fg-muted">Highlighted days have open times.</p>
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
                    @foreach(['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $day)
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
                                    aria-label="{{ $cell['label'] }}{{ $cell['available'] ? ', available' : ', unavailable' }}"
                                    aria-pressed="{{ $cell['selected'] ? 'true' : 'false' }}"
                                    class="h-full w-full rounded-xl text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed
                                        {{ $cell['selected'] ? 'bg-indigo-600 text-white shadow-md shadow-indigo-500/25' : '' }}
                                        {{ ! $cell['selected'] && $cell['available'] ? 'bg-indigo-500/10 text-indigo-700 ring-1 ring-inset ring-indigo-400/40 hover:bg-indigo-500/20 dark:text-indigo-200' : '' }}
                                        {{ ! $cell['available'] ? 'text-fg-faint opacity-50' : '' }}"
                                >
                                    {{ $cell['day'] }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if(empty($dates))
                    <div class="mt-4 rounded-2xl border border-dashed border-edge-strong px-4 py-5 text-center">
                        <p class="text-sm font-semibold text-fg-strong">No times are available this month.</p>
                        <p class="mt-1 text-sm text-fg-muted">{{ $canGoNextMonth ? 'Try the next month.' : 'Please check back soon.' }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if($showTimes)
        <section aria-labelledby="booking-time">
            <h3 id="booking-time" class="text-lg font-black text-fg-strong">Available times</h3>
            <p class="mt-1 text-sm text-fg-muted">{{ $selectedDay?->format('l, j F') }}</p>

            <div wire:loading.flex wire:target="selectDate" class="mt-5 min-h-24 items-center justify-center gap-3 text-sm text-fg-muted" role="status">
                <x-ui.spinner size="sm" />
                Loading times…
            </div>

            <div wire:loading.remove wire:target="selectDate" class="mt-4 space-y-5">
                @if(empty($slotGroups))
                    <div class="rounded-2xl border border-dashed border-edge-strong px-4 py-5 text-center">
                        <p class="text-sm font-semibold text-fg-strong">No times are available on this date.</p>
                        <p class="mt-1 text-sm text-fg-muted">Try another date.</p>
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

            @if($recurrenceSummary)
                <p class="mt-5 rounded-2xl bg-indigo-500/10 px-4 py-3 text-sm font-semibold text-indigo-800 dark:text-indigo-200" aria-live="polite">{{ $recurrenceSummary }}</p>
            @endif
        </section>
    @endif
</div>
