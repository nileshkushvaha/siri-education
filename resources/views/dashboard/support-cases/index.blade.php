@extends('layouts.account')

@section('title', 'Support Cases — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Support Cases'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-fg-strong">Support Cases</h1>
            <p class="text-fg-muted text-sm mt-1">Booking, payment, wallet, and other issues you've raised with support.</p>
        </div>
        <a href="{{ route('dashboard.support-cases.create') }}"
           class="inline-flex min-h-11 items-center px-4 py-2 rounded-lg bg-indigo-500 text-sm font-semibold text-white hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 flex-shrink-0">
            New Case
        </a>
    </div>

    <livewire:frontend.support-cases.support-case-list />

@endsection
