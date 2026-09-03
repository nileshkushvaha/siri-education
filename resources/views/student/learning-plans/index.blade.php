@extends('layouts.account')

@section('title', 'Learning Plans — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Learning Plans'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Learning Plans</h1>
        <p class="text-fg-muted text-sm mt-1">Your living academic contracts with assigned instructors.</p>
    </div>

    <livewire:frontend.student.learning-plans />
@endsection
