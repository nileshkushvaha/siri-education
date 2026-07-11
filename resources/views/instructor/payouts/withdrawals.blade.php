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
        <p class="text-sm text-slate-400">{{ now()->format('l, F j, Y') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-white">Withdrawals</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
            Request a payout of your released earnings to a verified payout method. Requests are reviewed before payment.
        </p>
    </div>

    <livewire:frontend.instructor.withdrawals-manager />
@endsection
