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
        <h1 class="text-xl font-bold text-fg-strong">Homework</h1>
        <p class="text-fg-muted text-sm mt-1">Review student submissions and add feedback.</p>
    </div>

    <livewire:frontend.instructor.homework-list />
@endsection
