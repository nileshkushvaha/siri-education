{{--
    One compact empty state for every chart and summary.

    Deliberately short — an empty dataset must not leave a chart-sized
    blank rectangle. It also distinguishes the three reasons a figure can
    be absent, because they mean very different things to an operator:

      healthy      — nothing to report, and that is good news
      no-activity  — nothing happened in the selected period
      unavailable  — the metric has no valid denominator / no data source

    @param string $message
    @param string $icon
    @param string $tone      healthy|no-activity|unavailable
    @param string $domain
    @param ?string $url
    @param ?string $linkLabel
--}}
@php
    $tone = $tone ?? 'no-activity';

    $toneLabel = match ($tone) {
        'healthy' => 'Healthy',
        'unavailable' => 'Not calculable',
        default => 'No activity in this period',
    };

    $toneClasses = match ($tone) {
        'healthy' => 'dash-pill-healthy',
        'unavailable' => 'dash-pill-idle',
        default => 'dash-pill-idle',
    };
@endphp

<div class="{{ $domain ?? 'dash-d-brand' }} dash-empty">
    <span class="dash-capsule h-9 w-9 opacity-80">
        <x-filament::icon :icon="$icon ?? 'heroicon-o-chart-bar'" class="h-4.5 w-4.5" />
    </span>

    <span class="dash-pill {{ $toneClasses }}">{{ $toneLabel }}</span>

    <p class="max-w-xs text-xs leading-relaxed text-gray-500 dark:text-gray-400">
        {{ $message }}
    </p>

    @if (! empty($url) && ! empty($linkLabel))
        <a
            href="{{ $url }}"
            class="group inline-flex items-center gap-1 rounded text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
        >
            {{ $linkLabel }}
            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
        </a>
    @endif
</div>
