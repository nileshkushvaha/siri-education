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
        <p class="text-sm text-slate-400">{{ now()->format('l, F j, Y') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-white">Payout Methods</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
            Add the bank account where your earnings should be paid. Details are encrypted, shown only as masked labels, and verified by our team before use.
        </p>
    </div>

    <livewire:frontend.instructor.payout-methods-manager />
@endsection
