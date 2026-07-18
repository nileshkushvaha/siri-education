@extends('layouts.account')

@section('title', 'My Students — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Students'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">My Students</h1>
        <p class="text-slate-400 text-sm mt-1">Students you have taught, with lesson history and learning-plan status.</p>
    </div>

    <livewire:frontend.instructor.student-list />
@endsection
