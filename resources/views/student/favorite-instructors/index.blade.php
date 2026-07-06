@extends('layouts.account')

@section('title', 'Favorite Instructors — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Favorite Instructors'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Favorite Instructors</h1>
        <p class="text-slate-400 text-sm mt-1">Keep approved instructors close for future lessons.</p>
    </div>

    <livewire:frontend.student.favorite-instructors />

@endsection
