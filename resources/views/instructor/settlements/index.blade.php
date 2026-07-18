@extends('layouts.account')

@section('title', 'Settlement History — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Settlement History'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Settlement History</h1>
        <p class="text-slate-400 text-sm mt-1">Track when your released earnings are batched and settled.</p>
    </div>

    <livewire:frontend.instructor.settlements-overview />
@endsection
