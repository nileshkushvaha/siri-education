@props([
    // An absolute instant (Carbon/DateTimeInterface or a UTC string).
    'value' => null,
    // 'datetime' | 'date' | 'time'
    'mode' => 'datetime',
    // Overrides the mode's default format when a surface needs its own.
    'format' => null,
    // Appends the IANA identifier — for deadlines and confirmations,
    // not for every table row.
    'label' => false,
    // Render for someone other than the logged-in user. Almost never
    // wanted: display follows the VIEWER, not the record's owner.
    'viewer' => null,
    // Shown when $value is null.
    'placeholder' => '—',
])

@php
    use App\Support\Timezone\ViewerDateTime;

    $local = ViewerDateTime::local($value, $viewer);

    $formatted = $local === null ? null : match (true) {
        $label === true => ViewerDateTime::labelled($value, $viewer, $format ?? ViewerDateTime::DATE_TIME),
        $mode === 'date' => $local->format($format ?? ViewerDateTime::DATE),
        $mode === 'time' => $local->format($format ?? ViewerDateTime::TIME),
        default => $local->format($format ?? ViewerDateTime::DATE_TIME),
    };
@endphp

@if ($local === null)
    {{ $placeholder }}
@else
    {{-- The machine-readable attribute stays the canonical UTC instant, so
         the markup never loses the unambiguous value even though the text
         is localized. --}}
    <time datetime="{{ $local->utc()->toIso8601String() }}" title="{{ $local->format(ViewerDateTime::DATE_TIME) }} ({{ $local->timezoneName }})" {{ $attributes }}>{{ $formatted }}</time>
@endif
