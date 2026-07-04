{{--
    Guest layout — the blessed entry point for new public-frontend pages
    (anonymous visitors: marketing, listings, booking, blog, FAQs, etc).

    Thin by design: it wraps layouts.frontend (the shared HTML shell —
    meta/SEO, analytics, Vite assets, Alpine, Livewire, header, footer,
    flash messages) so the full chrome, and nothing about it, is
    duplicated here. Naming it "guest" — rather than pointing new pages
    at "frontend" directly — gives this phase of work a stable,
    intention-revealing name to build against, decoupled from the
    generic base layout underneath.

    Usage:
        @extends('layouts.guest')
        @section('title', 'Page Title')
        @section('content')
            ...
        @endsection

    Yields/sections available (inherited from layouts.frontend):
        title, meta_description, content, breadcrumbs
        @stack('meta'|'structured_data'|'head'|'scripts')
--}}
@extends('layouts.frontend')
