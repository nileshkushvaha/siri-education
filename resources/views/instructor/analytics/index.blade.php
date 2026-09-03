@extends('layouts.account')

@section('title', 'Analytics — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Analytics'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Analytics</h1>
        <p class="text-fg-muted text-sm mt-1">A foundation view of your teaching performance across students, lessons, quality, and homework.</p>
    </div>

    <livewire:frontend.instructor.analytics-overview />
@endsection
