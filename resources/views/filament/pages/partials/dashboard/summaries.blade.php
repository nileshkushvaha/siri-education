{{--
    Compact domain summaries: at most three metrics each, plus one clear
    "open report" action. These are teasers that hand off to the owning
    report — never a second place to read a full breakdown.

    A summary the viewer may not see was never built, so nothing is
    hidden here; the grid just closes up.

    The money summary carries a provider-activation notice when no
    collection provider is live, so an empty external-payment figure is
    never mistaken for evidence of zero business.
--}}
@if (count($dashboard->summaries) > 0)
    <section aria-labelledby="dashboard-summaries-heading" class="space-y-4">
        <h2 id="dashboard-summaries-heading" class="text-base font-semibold text-gray-950 dark:text-white">
            By domain
        </h2>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($dashboard->summaries as $summary)
                <div class="fi-section flex h-full min-w-0 flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

                    <div class="flex items-center gap-2">
                        <span class="rounded-lg bg-gray-100 p-1.5 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <x-filament::icon :icon="$summary->icon" class="h-4 w-4" />
                        </span>
                        <h3 class="min-w-0 break-words text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $summary->title }}
                        </h3>
                    </div>

                    @if ($summary->notice !== null)
                        <p class="mt-3 flex items-start gap-1.5 rounded-lg bg-warning-50 p-2 text-[11px] leading-snug text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                            <x-filament::icon icon="heroicon-o-information-circle" class="mt-px h-3.5 w-3.5 shrink-0" />
                            <span>{{ $summary->notice }}</span>
                        </p>
                    @endif

                    <dl class="mt-3 flex-1 space-y-3">
                        @foreach ($summary->metrics as $metric)
                            <div class="min-w-0">
                                <dt class="break-words text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $metric->label }}
                                </dt>
                                <dd @class([
                                    'mt-0.5 break-words text-lg font-semibold tabular-nums',
                                    'text-gray-950 dark:text-white' => ! $metric->isUnavailable,
                                    'text-gray-400 dark:text-gray-500' => $metric->isUnavailable,
                                ])>
                                    {{ $metric->value }}
                                </dd>
                                @if ($metric->hint !== null)
                                    <p class="mt-0.5 break-words text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                                        {{ $metric->hint }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    @if ($summary->reportUrl !== null)
                        <a
                            href="{{ $summary->reportUrl }}"
                            class="mt-4 inline-flex items-center gap-1 self-start rounded-lg text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                        >
                            {{ $summary->reportLabel }}
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Optional fifth chart: rendered only in this permission-specific
             section, keeping the primary chart row at four. --}}
        @php
            $homeworkChart = collect($dashboard->charts)->firstWhere('key', 'homework_activity');
        @endphp

        @if ($homeworkChart !== null && ! $homeworkChart->isEmpty())
            <div class="fi-section min-w-0 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @livewire(
                    \App\Filament\Widgets\Dashboard\HomeworkActivityChartWidget::class,
                    $chartProps,
                    key('homework-activity-' . $chartKey)
                )

                @if ($homeworkChart->url !== null)
                    <a
                        href="{{ $homeworkChart->url }}"
                        class="mt-3 inline-flex items-center gap-1 rounded-lg text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                    >
                        Open Learning Analytics — Homework
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                    </a>
                @endif
            </div>
        @endif
    </section>
@endif
