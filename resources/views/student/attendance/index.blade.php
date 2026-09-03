@extends('layouts.account')

@section('title', 'Attendance — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Attendance'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Attendance</h1>
        <p class="text-fg-muted text-sm mt-1">Your session attendance record.</p>
    </div>

    <livewire:frontend.student.attendance-history />

@endsection
