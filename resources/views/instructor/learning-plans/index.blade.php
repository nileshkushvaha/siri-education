@extends('layouts.account')

@section('title', 'Assigned Learning Plans — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Assigned Learning Plans'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Assigned Learning Plans</h1>
        <p class="text-slate-400 text-sm mt-1">Record assessments, milestones, and reviews for students assigned to you.</p>
    </div>

    <livewire:frontend.instructor.learning-plans />
@endsection
