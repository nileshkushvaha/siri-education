@extends('layouts.account')

@section('title', 'Learning Progress — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Progress'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">My Progress</h1>
        <p class="text-fg-muted text-sm mt-1">Track your completed sessions, hours learned, and subjects studied.</p>
    </div>

    <livewire:frontend.student.progress-overview />

@endsection
