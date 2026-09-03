@extends('layouts.account')

@section('title', 'Refer a Friend — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Refer a Friend'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Refer a Friend</h1>
        <p class="text-fg-muted text-sm mt-1">Share your code and invite friends to start learning.</p>
    </div>

    <livewire:frontend.student.refer-friend />

@endsection
