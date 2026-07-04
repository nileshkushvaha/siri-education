@extends('layouts.account')

@section('title', 'My Bookings — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Bookings'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">My Bookings</h1>
        <p class="text-slate-400 text-sm mt-1">Full history of your booked sessions.</p>
    </div>

    <livewire:frontend.student.booking-history />

@endsection
