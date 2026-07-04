@extends('layouts.account')

@section('title', 'Homework — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Homework'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Homework</h1>
        <p class="text-slate-400 text-sm mt-1">Assignments from your teachers.</p>
    </div>

    <livewire:frontend.student.homework-list />

@endsection
