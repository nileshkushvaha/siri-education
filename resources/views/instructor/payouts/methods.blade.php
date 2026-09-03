@extends('layouts.account')

@section('title', 'Payout Methods — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Payout Methods'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-fg-strong">Payout Methods</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-fg-muted">
            Add the bank account where your earnings should be paid. Details are encrypted, shown only as masked labels, and verified by our team before use.
        </p>
    </div>

    <livewire:frontend.instructor.payout-methods-manager />
@endsection
