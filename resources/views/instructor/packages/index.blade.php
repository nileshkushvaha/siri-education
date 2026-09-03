@extends('layouts.account')

@section('title', 'Lesson Packages — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Lesson Packages'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-fg-strong">Lesson Packages</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-fg-muted">
            Offer a personalized lesson package to an existing student. Pricing is calculated automatically from your standard lesson price — every proposal is reviewed by an admin before the student sees it.
        </p>
    </div>

    <livewire:frontend.instructor.package-proposal-creator />
@endsection
