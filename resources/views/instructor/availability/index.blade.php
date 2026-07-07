@extends('layouts.account')

@section('title', 'Availability — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Availability'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm text-slate-400">{{ now()->format('l, F j, Y') }}</p>
            <h1 class="mt-2 text-3xl font-bold text-white">Teaching Availability</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
                Set the weekly hours students can book, then block dates when you are unavailable. Times are handled in your teaching timezone.
            </p>
        </div>
        <a href="{{ route('profile.show') }}" class="inline-flex items-center justify-center rounded-xl border border-white/[0.10] px-4 py-2 text-sm font-semibold text-slate-300 transition hover:bg-white/[0.05] hover:text-white">
            Edit profile timezone
        </a>
    </div>

    <livewire:frontend.instructor.availability-manager />
@endsection
