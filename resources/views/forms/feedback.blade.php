@extends('layouts.frontend')

@section('title', 'Feedback')
@section('meta_description', "Share what's working and what isn't — we read every message.")

@push('meta')
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ route('forms.feedback') }}">
    <meta property="og:title" content="Feedback">
    <meta property="og:description" content="Share what's working and what isn't — we read every message.">
    <meta property="og:url" content="{{ route('forms.feedback') }}">
    <meta property="og:type" content="website">
@endpush

@section('content')
<div class="min-h-screen bg-surface-dark">

    <div class="relative py-20 px-4 text-center" style="background: linear-gradient(180deg, rgba(99,102,241,0.12) 0%, transparent 100%)">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-4xl font-bold text-white mb-3">Share Your Feedback</h1>
            <p class="text-slate-400 mb-8">Tell us what's working and what isn't — we read every message.</p>
        </div>
    </div>

    <div class="max-w-xl mx-auto px-4 pb-20">
        <livewire:frontend.forms.feedback-form />
    </div>
</div>
@endsection
