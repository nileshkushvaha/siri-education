@extends('layouts.account')

@section('title', 'Learning Goals — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Learning Goals'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Learning Goals</h1>
        <p class="text-fg-muted text-sm mt-1">Set clear goals for what you want to learn next.</p>
    </div>

    <livewire:frontend.student.learning-goals />

@endsection
