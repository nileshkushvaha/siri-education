@extends('layouts.account')

@section('title', 'Upcoming Classes — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Upcoming Classes'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Upcoming Classes</h1>
        <p class="text-fg-muted text-sm mt-1">Your confirmed and pending sessions.</p>
    </div>

    <livewire:frontend.student.upcoming-classes />

@endsection
