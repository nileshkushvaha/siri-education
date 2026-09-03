@extends('layouts.account')

@section('title', 'My Orders — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Orders'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Orders</h1>
        <p class="text-fg-muted text-sm mt-1">Your purchase history, invoices, and receipts.</p>
    </div>

    <x-account.card title="Order History">
        <x-student.coming-soon
            icon="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"
            title="No Orders Yet"
            message="Your purchase history will appear here once you buy a course." />
    </x-account.card>

@endsection
