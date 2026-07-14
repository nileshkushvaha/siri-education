@extends('layouts.account')

@section('title', 'My Reviews — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Reviews'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Reviews</h1>
        <p class="text-slate-400 text-sm mt-1">Review your completed lessons and manage what you have shared.</p>
    </div>

    <livewire:frontend.student.reviews-portal />

@endsection
