@extends('layouts.account')

@section('title', 'Instructor Onboarding — ' . config('app.name'))
@section('account-full-width', 'true')

@section('account-breadcrumbs')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-account.breadcrumb :crumbs="[
            ['label' => 'Dashboard', 'url' => route('dashboard')],
            ['label' => 'Instructor Onboarding'],
        ]" />

        <a href="{{ route('dashboard') }}"
           class="inline-flex w-fit items-center justify-center rounded-lg border border-white/[0.10] px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-white/[0.05]">
            Back to Dashboard
        </a>
    </div>
@endsection

@section('account-content')
    <livewire:frontend.instructor.onboarding-wizard />
@endsection
