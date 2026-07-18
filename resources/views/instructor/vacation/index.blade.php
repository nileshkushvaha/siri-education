@extends('layouts.account')

@section('title', 'Vacation Mode — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Vacation Mode'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Vacation Mode</h1>
        <p class="text-slate-400 text-sm mt-1">Temporarily pause new lesson bookings without touching your schedule or existing lessons.</p>
    </div>

    <livewire:frontend.instructor.vacation-mode-manager />
@endsection
