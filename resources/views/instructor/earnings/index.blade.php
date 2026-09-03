@extends('layouts.account')

@section('title', 'My Earnings — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Earnings'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">My Earnings</h1>
        <p class="text-fg-muted text-sm mt-1">Your earning history and status, lesson by lesson.</p>
    </div>

    <livewire:frontend.instructor.earnings-overview />
@endsection
