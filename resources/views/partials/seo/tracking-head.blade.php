{{--
    Search-console verification and analytics/tag snippets that belong in <head>.

    Single source for every layout. This markup previously existed as three
    near-identical copies (layouts/landing, layouts/page, layouts/frontend) using
    two different variable conventions — `$site[...]` from ContentRenderer in the
    CMS layouts and locally-resolved `$gaId`/`$gtmId`/… in the frontend layout.
    Copies drift, and the layouts that were written later (auth, guest, error)
    simply never got one, so an administrator's Analytics ID silently applied to
    part of the site only.

    Settings are read here rather than passed in so a layout cannot forget to
    supply them. Resolution is wrapped in rescue() because this partial is
    included by layouts/error, which renders when the application is already
    failing — a database outage must not turn a handled 500 into an unhandled
    one. rescue(..., report: false) keeps that path from re-reporting the very
    failure that is already being rendered.

    Pair with partials/seo/tracking-body.blade.php, which carries the <noscript>
    fallbacks that must sit at the top of <body>.
--}}
@php
    $seoTracking = $seoTracking ?? rescue(fn () => app(\App\Settings\SeoSettings::class), null, false);

    $gscVerification = $seoTracking->google_search_console_verification ?? null;
    $gtmId = $seoTracking->google_tag_manager_id ?? null;
    $gaId = $seoTracking->google_analytics_id ?? null;
@endphp

@if (filled($gscVerification))
    <meta name="google-site-verification" content="{{ $gscVerification }}">
@endif

@if (filled($gtmId))
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
@endif

@if (filled($gaId))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $gaId }}');</script>
@endif

{{-- The Meta Pixel loader lives in tracking-body, not here: that is where both
     pre-existing copies placed it, and this extraction deliberately preserves
     tag placement rather than quietly changing when tags fire. --}}
