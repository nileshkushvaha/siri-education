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
        <h1 class="text-3xl font-bold text-fg-strong">Reviews & Quality</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-fg-muted">
            A private summary of your published student ratings and feedback. Only you can see this page.
        </p>
    </div>

    <livewire:frontend.instructor.quality-insights-overview />
@endsection
