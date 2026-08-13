@extends('layouts.account')

@section('title', 'Packages — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Packages'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Packages</h1>
        <p class="text-slate-400 text-sm mt-1">Personalized lesson packages approved for you by an instructor.</p>
    </div>

    <livewire:frontend.student.package-proposals />
@endsection
