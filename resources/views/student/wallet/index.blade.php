@extends('layouts.account')

@section('title', 'Wallet — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Wallet'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Wallet</h1>
        <p class="text-fg-muted text-sm mt-1">Your balance and activity statement.</p>
    </div>

    <livewire:frontend.student.wallet-overview />

@endsection
