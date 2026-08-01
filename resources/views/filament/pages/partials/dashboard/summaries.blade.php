{{--
    Compact domain summaries: at most three metrics each, plus one clear
    "Open report" action. These are teasers that hand off to the owning
    report — never a second place to read a full breakdown, and never a
    miniature table.

    A summary the viewer may not see was never built, so nothing is
    hidden here; the grid just closes up.

    The money summary carries a provider-activation notice when no
    collection provider is live, styled as configuration information
    rather than a transaction failure — an empty external-payment figure
    must never be mistaken for evidence of zero business.
--}}
@if (count($dashboard->summaries) > 0)
    <section aria-labelledby="dashboard-summaries-heading">

        @include('filament.pages.partials.dashboard._section-heading', [
            'id' => 'dashboard-summaries-heading',
            'eyebrow' => 'Domains',
            'title' => 'By domain',
            'purpose' => 'Health across the platform.',
            'icon' => 'heroicon-m-squares-2x2',
            'domain' => 'dash-d-market',
        ])

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($dashboard->summaries as $summary)
                @php
                    $domain = \App\Dashboard\Support\DashboardPalette::domainClass($summary->key);
                    $available = collect($summary->metrics)->reject(fn ($m) => $m->isUnavailable)->count();
                    $total = max(1, count($summary->metrics));
                @endphp

                <div class="{{ $domain }} dash-card dash-wash h-full p-4">

                    <div class="flex items-center gap-2.5">
                        <span class="dash-capsule h-8 w-8 shrink-0">
                            <x-filament::icon :icon="$summary->icon" class="h-4 w-4" />
                        </span>
                        <h3 class="min-w-0 break-words text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $summary->title }}
                        </h3>
                    </div>

                    {{-- How much of this domain is currently reportable —
                         a real signal, derived from the metrics shown. --}}
                    <div class="mt-3" role="img"
                         aria-label="{{ $available }} of {{ $total }} metrics in this domain are currently calculable.">
                        <div class="dash-rail">
                            <span style="width: {{ round($available / $total * 100) }}%"></span>
                        </div>
                        <p class="mt-1 text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $available }} of {{ $total }} calculable
                        </p>
                    </div>

                    @if ($summary->notice !== null)
                        <div class="mt-3 flex items-start gap-2 rounded-lg border border-warning-500/25 bg-warning-50 p-2.5 dark:bg-warning-500/10">
                            <x-filament::icon icon="heroicon-m-cog-6-tooth" class="mt-px h-3.5 w-3.5 shrink-0 text-warning-600 dark:text-warning-400" />
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold text-warning-800 dark:text-warning-300">Configuration, not activity</p>
                                <p class="mt-0.5 text-[11px] leading-snug text-warning-800/90 dark:text-warning-300/90">
                                    {{ $summary->notice }}
                                </p>
                            </div>
                        </div>
                    @endif

                    <dl class="mt-3 flex-1 space-y-3">
                        @foreach ($summary->metrics as $metric)
                            <div class="min-w-0">
                                <dt class="break-words text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $metric->label }}
                                </dt>
                                <dd @class([
                                    'mt-0.5 break-words text-lg font-bold leading-tight tabular-nums',
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
                            class="group mt-4 inline-flex items-center gap-1.5 self-start rounded-lg border border-gray-950/10 px-2.5 py-1.5 text-[11px] font-semibold text-gray-700 transition-colors hover:bg-gray-950/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            {{ $summary->reportLabel }}
                            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Optional fifth chart: rendered only in this permission-specific
             section, keeping the primary chart row at four. --}}
        @php $homeworkChart = collect($dashboard->charts)->firstWhere('key', 'homework_activity'); @endphp

        @if ($homeworkChart !== null)
            <div class="dash-d-students dash-card dash-wash mt-4 min-w-0 p-4">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5">
                    <div class="min-w-0">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                            <span class="dash-capsule h-6 w-6 shrink-0">
                                <x-filament::icon icon="heroicon-o-academic-cap" class="h-3 w-3" />
                            </span>
                            {{ $homeworkChart->title }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $homeworkChart->subtitle }}</p>
                    </div>

                    @if (! $homeworkChart->isEmpty())
                        <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1">
                            @foreach ($homeworkChart->datasets as $dataset)
                                <p class="text-right">
                                    <span class="block text-lg font-bold leading-none tabular-nums text-gray-950 dark:text-white">
                                        {{ number_format(array_sum($dataset['data'])) }}
                                    </span>
                                    <span class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $dataset['label'] }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($homeworkChart->isEmpty())
                    <div class="mt-3">
                        @include('filament.pages.partials.dashboard._empty-state', [
                            'message' => $homeworkChart->emptyMessage ?? 'No homework activity in this period.',
                            'icon' => 'heroicon-o-academic-cap',
                            'tone' => 'no-activity',
                            'domain' => 'dash-d-students',
                            'url' => $homeworkChart->url,
                            'linkLabel' => 'Open Learning Analytics',
                        ])
                    </div>
                @else
                    <div class="dash-plot mt-3 min-w-0 p-3">
                        @livewire(\App\Filament\Widgets\Dashboard\HomeworkActivityChartWidget::class, $chartProps, key('homework-activity-'.$chartKey))
                    </div>

                    @if ($homeworkChart->url !== null)
                        <a
                            href="{{ $homeworkChart->url }}"
                            class="group mt-3 inline-flex items-center gap-1 self-start rounded text-xs font-semibold text-gray-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-200"
                        >
                            Open Learning Analytics — Homework
                            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                        </a>
                    @endif
                @endif
            </div>
        @endif
    </section>
@endif
