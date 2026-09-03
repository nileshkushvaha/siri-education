@extends('layouts.account')

@section('title', 'Withdrawals — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Withdrawals'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-fg-strong">Withdrawals</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-fg-muted">
            Request a payout of your released earnings to a verified payout method. Requests are reviewed before payment.
        </p>
    </div>

    <livewire:frontend.instructor.withdrawals-manager />
@endsection
