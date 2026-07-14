@extends('layouts.account')

@section('title', 'Reviews & Quality — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Reviews & Quality'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <p class="text-sm text-slate-400">{{ now()->format('l, F j, Y') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-white">Reviews & Quality</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
            A private summary of your published student ratings and feedback. Only you can see this page.
        </p>
    </div>

    <livewire:frontend.instructor.quality-insights-overview />
@endsection
