@extends('layouts.frontend')

@section('content')
@php
    $accountFullWidth = trim($__env->yieldContent('account-full-width')) === 'true';
@endphp

<div class="min-h-screen bg-surface-dark">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex gap-6">

            {{-- ── Left Sidebar ─────────────────────────────────────────── --}}
            @unless($accountFullWidth)
                <x-account.sidebar :menu="$accountMenu ?? []" />
            @endunless

            {{-- ── Main Content ─────────────────────────────────────────── --}}
            <div class="flex-1 min-w-0">
                @hasSection('account-breadcrumbs')
                    @yield('account-breadcrumbs')
                @endif
                <x-account.flash-messages />
                @yield('account-content')
            </div>

        </div>
    </div>
</div>
@endsection
