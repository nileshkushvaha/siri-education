@extends('layouts.frontend')

@section('title', 'Support')
@section('meta_description', 'Having an issue? Let us know and our support team will help.')

@push('meta')
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ route('forms.support') }}">
    <meta property="og:title" content="Support">
    <meta property="og:description" content="Having an issue? Let us know and our support team will help.">
    <meta property="og:url" content="{{ route('forms.support') }}">
    <meta property="og:type" content="website">
@endpush

@section('content')
<div class="min-h-screen bg-surface-dark">

    <div class="relative py-20 px-4 text-center" style="background: linear-gradient(180deg, rgba(99,102,241,0.12) 0%, transparent 100%)">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-4xl font-bold text-white mb-3">Get Support</h1>
            <p class="text-slate-400 mb-8">Having an issue? Let us know and our team will help.</p>
        </div>
    </div>

    <div class="max-w-xl mx-auto px-4 pb-20">
        <livewire:frontend.forms.support-form />
    </div>
</div>
@endsection
