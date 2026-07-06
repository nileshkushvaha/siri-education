@php
    $generalSettings = app(\App\Settings\GeneralSettings::class);
    $seoSettings     = app(\App\Settings\SeoSettings::class);
    $toStorageUrl = fn(?string $p): ?string => blank($p) ? null : (str_starts_with($p, 'http') || str_starts_with($p, '//') ? $p : Storage::disk('public')->url($p));
    $favicon         = $toStorageUrl($generalSettings->favicon ?? null);
    $logo            = $toStorageUrl($generalSettings->logo ?? null);
    $appName         = $generalSettings->app_name ?? config('app.name');
    $footerCopyright = $generalSettings->footer_copyright ?? null;
    $footerText      = $generalSettings->footer_text ?? null;
    $supportEmail    = $generalSettings->support_email ?? null;
    $supportPhone    = $generalSettings->support_phone ?? null;
    $address         = $generalSettings->address ?? null;
    $gaId            = $seoSettings->google_analytics_id ?? null;
    $gtmId           = $seoSettings->google_tag_manager_id ?? null;
    $pixelId         = $seoSettings->facebook_pixel_id ?? null;
    $gscVerification = $seoSettings->google_search_console_verification ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $appName)</title>
    <meta name="description" content="@yield('meta_description', '')">

    @if($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @else
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'><stop offset='0%25' stop-color='%236366f1'/><stop offset='100%25' stop-color='%238b5cf6'/></linearGradient></defs><rect width='32' height='32' rx='8' fill='url(%23g)'/><text x='16' y='22' font-size='18' text-anchor='middle' fill='white'>E</text></svg>">
    @endif

    @stack('meta')

    @if($gscVerification)
        <meta name="google-site-verification" content="{{ $gscVerification }}">
    @endif

    @stack('structured_data')

    @if($gtmId)
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Alpine is NOT loaded here anymore. Livewire 4 (@livewireStyles /
         @livewireScripts, below) bundles and auto-starts its own Alpine
         instance; a second, separately-loaded Alpine (as this used to be)
         causes "Detected multiple instances of Alpine running" and
         Livewire-internal errors like "Alpine.transaction is not a
         function". The collapse plugin Livewire's Alpine needs is
         registered via resources/js/frontend/alpine.js instead. --}}

    @include('partials.head-styles')

    @if($gaId)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $gaId }}');</script>
    @endif

    @stack('head')

    {{-- Livewire 4 (bundled via Filament ^5.6) powers frontend interactivity
         alongside Alpine — see docs/frontend.md for component conventions. --}}
    @livewireStyles
</head>
<body class="text-slate-800 antialiased" style="background: linear-gradient(160deg, #f8f7ff 0%, #f0ebff 30%, #e8f4ff 60%, #f5f0ff 100%); min-height: 100vh;">

    @if($gtmId)
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif

    @if($pixelId)
        <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{{ $pixelId }}');fbq('track','PageView');</script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1"/></noscript>
    @endif

    @hasSection('bare')
        @yield('content')
    @else
        <livewire:frontend.layout.announcement-bar />
        <livewire:frontend.layout.site-header :app-name="$appName" :logo="$logo" />
        <livewire:frontend.layout.search-overlay />

        {{-- Flash messages — Account Portal pages render their own dark-themed
             flash messages inside layouts.account, below the breadcrumb, so
             this global (light-themed) block is skipped for them. --}}
        @unless($__env->hasSection('account-content') || $__env->hasSection('page-flash'))
            @if(session()->has('success') || session()->has('error') || session()->has('warning') || session()->has('info'))
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 space-y-2">
                @if(session('success'))
                <div class="flex items-center gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-400 animate-fade-in-up">
                    <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    {{ session('success') }}
                </div>
                @endif
                @if(session('error'))
                <div class="flex items-center gap-3 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-400 animate-fade-in-up">
                    <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    {{ session('error') }}
                </div>
                @endif
                @if(session('warning'))
                <div class="flex items-center gap-3 rounded-xl bg-amber-500/10 border border-amber-500/20 px-4 py-3 text-sm text-amber-400 animate-fade-in-up">
                    <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    {{ session('warning') }}
                </div>
                @endif
                @if(session('info'))
                <div class="flex items-center gap-3 rounded-xl bg-blue-500/10 border border-blue-500/20 px-4 py-3 text-sm text-blue-400 animate-fade-in-up">
                    <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                    {{ session('info') }}
                </div>
                @endif
            </div>
            @endif
        @endunless

        @hasSection('breadcrumbs')
            @yield('breadcrumbs')
        @endif

        @yield('content')

        <livewire:frontend.layout.site-footer
            :app-name="$appName"
            :logo="$logo"
            :footer-text="$footerText"
            :footer-copyright="$footerCopyright"
            :support-email="$supportEmail"
            :support-phone="$supportPhone"
            :address="$address"
        />
        <livewire:frontend.layout.cookie-banner />
    @endif

    @stack('scripts')
    @livewireScripts
</body>
</html>
