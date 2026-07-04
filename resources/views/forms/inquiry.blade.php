@extends('layouts.frontend')

@section('title', 'General Inquiry')
@section('meta_description', "Have a question that doesn't fit elsewhere? Send it our way.")

@push('meta')
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ route('forms.inquiry') }}">
    <meta property="og:title" content="General Inquiry">
    <meta property="og:description" content="Have a question that doesn't fit elsewhere? Send it our way.">
    <meta property="og:url" content="{{ route('forms.inquiry') }}">
    <meta property="og:type" content="website">
@endpush

@section('content')
<div class="min-h-screen bg-surface-dark">

    <div class="relative py-20 px-4 text-center" style="background: linear-gradient(180deg, rgba(99,102,241,0.12) 0%, transparent 100%)">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-4xl font-bold text-white mb-3">General Inquiry</h1>
            <p class="text-slate-400 mb-8">Have a question that doesn't fit elsewhere? Send it our way.</p>
        </div>
    </div>

    <div class="max-w-xl mx-auto px-4 pb-20">
        <livewire:frontend.forms.general-inquiry-form />
    </div>
</div>
@endsection
