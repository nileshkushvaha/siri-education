@extends('layouts.account')

@section('title', $student->name . ' — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Students', 'url' => route('dashboard.instructor.students')],
        ['label' => $student->name],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">{{ $student->name }}</h1>
        <p class="text-slate-400 text-sm mt-1">Your teaching relationship with this student.</p>
    </div>

    <livewire:frontend.instructor.student-detail :student="$student" />
@endsection
