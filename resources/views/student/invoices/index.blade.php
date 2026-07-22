@extends('layouts.account')

@section('title', 'Invoices — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Invoices'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Invoices</h1>
        <p class="text-slate-400 text-sm mt-1">Receipts for your successful lesson payments and wallet recharges.</p>
    </div>

    <livewire:frontend.student.invoice-list />

@endsection
