@extends('layouts.account')

@section('title', 'Notifications — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Notifications'],
    ]" />
@endsection

@section('account-content')

    <livewire:frontend.student.notifications-panel />

@endsection
