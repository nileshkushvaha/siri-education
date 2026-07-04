{{--
    Wraps the matched term in <mark> — <x-ui.search-highlight :text="$title" :term="$query" />
    Safe by construction: $text is escaped via e() first, then the
    highlight regex only wraps substrings already inside that escaped
    string, so no unescaped HTML from $text or $term ever reaches the
    page.
--}}
@props(['text', 'term'])

@php
    $safeText = e((string) $text);
    $safeTerm = trim((string) $term);

    $highlighted = $safeTerm !== ''
        ? preg_replace('/('.preg_quote($safeTerm, '/').')/i', '<mark class="rounded-sm bg-indigo-400/30 text-inherit">$1</mark>', $safeText)
        : $safeText;
@endphp

{!! $highlighted !!}
