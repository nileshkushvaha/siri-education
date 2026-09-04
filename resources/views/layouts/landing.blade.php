@php
    $appName  = $site['app_name']  ?? config('app.name');
    $favicon  = $site['favicon']   ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $seo['title'] ?? $appName }}</title>

    @if($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @else
        <link rel="icon" type="image/svg+xml" href="{{ asset('images/brand/siri-mark.svg') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/apple-touch-icon.png') }}">
    @endif

    @if(isset($seo))
        <meta name="description" content="{{ $seo['description'] ?? '' }}">
        @if($seo['keywords'] ?? false)<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
        <meta name="robots" content="{{ $seo['robots'] ?? 'index, follow' }}">
        @if($seo['canonical'] ?? false)<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
        <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
        <meta property="og:title" content="{{ $seo['og_title'] ?? ($seo['title'] ?? '') }}">
        <meta property="og:description" content="{{ $seo['og_description'] ?? ($seo['description'] ?? '') }}">
        <meta property="og:url" content="{{ $seo['og_url'] ?? ($seo['canonical'] ?? '') }}">
        <meta property="og:site_name" content="{{ $appName }}">
        @if($seo['og_image'] ?? false)
            <meta property="og:image" content="{{ $seo['og_image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
        @endif
        <meta name="twitter:card" content="{{ $seo['twitter_card'] ?? 'summary_large_image' }}">
        <meta name="twitter:title" content="{{ $seo['og_title'] ?? ($seo['title'] ?? '') }}">
        <meta name="twitter:description" content="{{ $seo['og_description'] ?? ($seo['description'] ?? '') }}">
        @if($seo['og_image'] ?? false)
            <meta name="twitter:image" content="{{ $seo['og_image'] }}">
        @endif
    @endif

    @include('partials.seo.tracking-head')

    @if(!empty($structured_data))
        <script type="application/ld+json">{!! json_encode($structured_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    {{-- Inter is self-hosted (see resources/css/app.css); this preload starts
         the download alongside the stylesheet rather than after it parses. --}}
    {{-- Skipped under the Vite dev server: there the CSS loads the font from
         Vite's origin (proxied to Laravel), so this URL would never be consumed. --}}
    @unless(app(\Illuminate\Foundation\Vite::class)->isRunningHot())
        <link rel="preload" as="font" type="font/woff2" href="{{ asset('fonts/inter-latin.woff2') }}" crossorigin>
    @endunless

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Alpine is NOT loaded here — see layouts/page.blade.php for why:
         a second, separately-loaded Alpine conflicts with the one
         Livewire bundles via @livewireStyles/@livewireScripts. --}}

    @include('partials.head-styles')

</head>
<body class="text-slate-800 antialiased" style="background: linear-gradient(160deg, #f8f7ff 0%, #f0ebff 30%, #e8f4ff 60%, #f5f0ff 100%); min-height: 100vh;">

    @include('partials.seo.tracking-body')

    {{-- Landing pages have no global header or footer — full conversion focus --}}
    <main id="main-content" class="{{ $content_width_classes ?? 'w-full' }}" data-content-width="{{ $content_width ?? 'default' }}">
        {!! $content ?? '' !!}
    </main>

</body>
</html>
