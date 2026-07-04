{{--
    Student layout — the blessed entry point for new authenticated
    frontend-portal pages (the "student" audience per PortalResolver's
    Frontend Portal: instructor, student, and future non-admin roles).

    This is a thin, same-yields alias over layouts.account, which
    already implements the full portal chrome (dark theme, sidebar via
    <x-account.sidebar>, dark-themed flash messages) and is used by
    9+ existing dashboard pages. It is NOT a redesign or a parallel
    implementation — new pages get a stable, audience-named layout to
    extend; existing pages keep extending layouts.account unchanged.

    $accountMenu / $accountProfileSummary are injected automatically by
    AccountPortalComposer (registered in FrontendServiceProvider) when the
    rendering view is listed there — add new view names to that
    composer's view list as pages are built.

    Usage:
        @extends('layouts.student')
        @section('account-breadcrumbs')
            ...
        @endsection
        @section('account-content')
            ...
        @endsection
--}}
@extends('layouts.account')
