@php
    $appName = $site['app_name'] ?? config('app.name');
    $favicon = $site['favicon'] ?? null;
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
    @endif

    @if(isset($seo))
        <meta name="description" content="{{ $seo['description'] ?? '' }}">
        @if($seo['keywords'] ?? false)<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
        <meta name="robots" content="{{ $seo['robots'] ?? 'index, follow' }}">
        @if($seo['canonical'] ?? false)<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
    @endif

    @if(!empty($structured_data))
        <script type="application/ld+json">{!! json_encode($structured_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Alpine is NOT loaded here — see layouts/page.blade.php for why:
         a second, separately-loaded Alpine conflicts with the one
         Livewire bundles via @livewireStyles/@livewireScripts. --}}

    @include('partials.head-styles')
    @include('partials.seo.tracking-head')
</head>
<body class="text-slate-800 antialiased" style="font-family:'Inter',ui-sans-serif,system-ui,sans-serif; background:#ffffff;">
    @include('partials.seo.tracking-body')

    <main id="main-content" class="{{ $content_width_classes ?? 'w-full' }}" data-content-width="{{ $content_width ?? 'default' }}">
        {!! $content ?? '' !!}
    </main>

</body>
</html>
