@extends('layouts.account')

@section('title', 'Messages — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Messages'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Messages</h1>
        <p class="text-fg-muted text-sm mt-1">Conversations tied to a confirmed booking or active learning plan.</p>
    </div>

    <livewire:frontend.messaging.conversation-list />

@endsection
