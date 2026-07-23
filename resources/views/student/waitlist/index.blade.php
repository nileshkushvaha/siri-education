@extends('layouts.account')

@section('title', 'Waitlist — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Waitlist'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Waitlist</h1>
        <p class="text-slate-400 text-sm mt-1">Instructors you're waiting to hear from when new availability opens.</p>
    </div>

    <livewire:frontend.student.waitlist-entry-list />

@endsection
