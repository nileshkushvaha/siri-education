@extends('layouts.account')

@section('title', 'Package Offers — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Package Offers'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <p class="text-sm text-slate-400">{{ now()->format('l, F j, Y') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-white">Package Offers</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
            Offer a personalized lesson package to an existing student. Pricing is calculated automatically from your standard lesson price — every proposal is reviewed by an admin before the student sees it.
        </p>
    </div>

    <livewire:frontend.instructor.package-proposal-creator />
@endsection
