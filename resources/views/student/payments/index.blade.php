@extends('layouts.account')

@section('title', 'Payments — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Payments'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Payments</h1>
        <p class="text-slate-400 text-sm mt-1">Your payment history for booked sessions.</p>
    </div>

    <livewire:frontend.student.payment-history />

@endsection
