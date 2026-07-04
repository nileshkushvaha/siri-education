@extends('layouts.frontend')

@section('title', 'Request a Callback')
@section('meta_description', 'Leave your number and a preferred time and our team will call you back.')

@push('meta')
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ route('forms.callback') }}">
    <meta property="og:title" content="Request a Callback">
    <meta property="og:description" content="Leave your number and a preferred time and our team will call you back.">
    <meta property="og:url" content="{{ route('forms.callback') }}">
    <meta property="og:type" content="website">
@endpush

@section('content')
<div class="min-h-screen bg-surface-dark">

    {{-- Hero --}}
    <div class="relative py-20 px-4 text-center" style="background: linear-gradient(180deg, rgba(99,102,241,0.12) 0%, transparent 100%)">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-4xl font-bold text-white mb-3">Request a Callback</h1>
            <p class="text-slate-400 mb-8">Leave your number and a preferred time — we'll call you back.</p>
        </div>
    </div>

    <div class="max-w-xl mx-auto px-4 pb-20">
        <livewire:frontend.forms.callback-form />
    </div>
</div>
@endsection
