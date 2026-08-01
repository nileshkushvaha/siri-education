{{--
    <noscript> fallbacks for the tags emitted by partials/seo/tracking-head.

    These must be the first thing inside <body> (GTM's documented placement),
    which is why they are a separate partial rather than part of the head one.

    Settings resolution mirrors tracking-head, including the rescue() guard for
    layouts/error — see the note there.
--}}
@php
    $seoTracking = $seoTracking ?? rescue(fn () => app(\App\Settings\SeoSettings::class), null, false);

    $gtmId = $seoTracking->google_tag_manager_id ?? null;
    $pixelId = $seoTracking->facebook_pixel_id ?? null;
@endphp

@if (filled($gtmId))
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif

@if (filled($pixelId))
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1"/></noscript>
@endif
