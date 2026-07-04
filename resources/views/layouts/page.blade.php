@php
    // Normalize $site variables to the same names the partials expect
    $appName         = $site['app_name'] ?? config('app.name');
    $logo            = $site['logo'] ?? null;
    $favicon         = $site['favicon'] ?? null;
    $footerCopyright = $site['footer_copyright'] ?? null;
    $footerText      = $site['footer_text'] ?? null;
    // Contact fields are not in ContentRenderer's getSiteMetadata() — load lazily
    $generalSettings = app(\App\Settings\GeneralSettings::class);
    $supportEmail    = $generalSettings->support_email ?? null;
    $supportPhone    = $generalSettings->support_phone ?? null;
    $address         = $generalSettings->address ?? null;
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
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'><stop offset='0%25' stop-color='%236366f1'/><stop offset='100%25' stop-color='%238b5cf6'/></linearGradient></defs><rect width='32' height='32' rx='8' fill='url(%23g)'/><text x='16' y='22' font-size='18' text-anchor='middle' fill='white'>E</text></svg>">
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

    @if($site['google_search_console_verification'] ?? false)
        <meta name="google-site-verification" content="{{ $site['google_search_console_verification'] }}">
    @endif

    @if(!empty($structured_data))
        <script type="application/ld+json">{!! json_encode($structured_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    @if($site['google_tag_manager_id'] ?? false)
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $site['google_tag_manager_id'] }}');</script>
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>

    @include('partials.head-styles')

    @if($site['google_analytics_id'] ?? false)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $site['google_analytics_id'] }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $site['google_analytics_id'] }}');</script>
    @endif

    @livewireStyles
</head>
<body class="text-slate-800 antialiased" style="background: linear-gradient(160deg, #f8f7ff 0%, #f0ebff 30%, #e8f4ff 60%, #f5f0ff 100%); min-height: 100vh;">

    @if($site['google_tag_manager_id'] ?? false)
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $site['google_tag_manager_id'] }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    @endif

    @if($site['facebook_pixel_id'] ?? false)
        <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{{ $site['facebook_pixel_id'] }}');fbq('track','PageView');</script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $site['facebook_pixel_id'] }}&ev=PageView&noscript=1"/></noscript>
    @endif

    <livewire:frontend.layout.announcement-bar />
    <livewire:frontend.layout.site-header :app-name="$appName" :logo="$logo" />
    <livewire:frontend.layout.search-overlay />

    @if(session()->has('success') || session()->has('error') || session()->has('warning') || session()->has('info'))
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 space-y-2">
        @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-400">
            <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div class="flex items-center gap-3 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-400">
            <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            {{ session('error') }}
        </div>
        @endif
    </div>
    @endif

    <main id="main-content">
        {{-- ── Page hero: vibrant animated gradient banner — hidden when this page IS the homepage ── --}}
        @php
            $homepageSettings = app(\App\Settings\GeneralSettings::class);
            $isHomepage = ($homepageSettings->homepage_display ?? 'template') === 'static_page'
                       && $homepageSettings->homepage_id
                       && ($page->id ?? null) === $homepageSettings->homepage_id;
        @endphp
        @if(($page ?? null) && ($page->title ?? false) && !$isHomepage)
        <div class="relative overflow-hidden" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 40%, #9333ea 70%, #ec4899 100%); min-height: 220px;">

            {{-- Animated blob orbs --}}
            <div class="blob-orb animate-blob w-72 h-72 -top-20 -right-10" style="background: rgba(236,72,153,0.5);"></div>
            <div class="blob-orb animate-blob anim-delay-400 w-64 h-64 bottom-0 -left-16" style="background: rgba(99,102,241,0.6); animation-delay: 2s;"></div>
            <div class="blob-orb animate-float w-40 h-40 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2" style="background: rgba(167,139,250,0.3); animation-delay: 1s;"></div>

            {{-- Mesh grid overlay --}}
            <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.15) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.15) 1px, transparent 1px); background-size: 40px 40px;"></div>

            {{-- Shine sweep --}}
            <div class="absolute inset-0 opacity-20" style="background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,0.3) 50%, transparent 60%); background-size: 200% 100%; animation: shimmer 3s ease-in-out infinite;"></div>

            {{-- Content — centred --}}
            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 lg:py-20 text-center animate-fade-in-up">

                {{-- Breadcrumb --}}
                <div class="flex items-center justify-center gap-2 text-sm mb-5">
                    <a href="{{ url('/') }}" class="text-white/70 hover:text-white transition-colors flex items-center gap-1">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                        Home
                    </a>
                    <svg class="h-3.5 w-3.5 text-white/40" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    <span class="text-white/90 font-medium">{{ $page->title }}</span>
                </div>

                {{-- Title --}}
                <h1 class="text-4xl lg:text-5xl xl:text-6xl font-extrabold tracking-tight text-white leading-[1.1] drop-shadow-lg">
                    {{ $page->title }}
                </h1>

                @if($page->excerpt ?? false)
                    <p class="mt-5 text-lg text-white/75 leading-relaxed">{{ $page->excerpt }}</p>
                @endif
            </div>

            {{-- Bottom wave --}}
            <div class="absolute bottom-0 left-0 right-0 h-8 overflow-hidden">
                <svg viewBox="0 0 1440 32" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" class="w-full h-full">
                    <path d="M0 32 C360 0 1080 0 1440 32 L1440 32 L0 32Z" style="fill: linear-gradient(160deg, #f8f7ff 0%, #f0ebff 30%, #e8f4ff 60%, #f5f0ff 100%)"></path>
                    <path d="M0 32 C360 0 1080 0 1440 32 L1440 32 L0 32Z" fill="#f8f7ff"></path>
                </svg>
            </div>
        </div>
        @endif

        @if($isHomepage ?? false)
            @include('partials.home-banner')
        @endif

        {!! $content ?? '' !!}
    </main>

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

    @livewireScripts

</body>
</html>
