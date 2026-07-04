@extends('layouts.frontend')

@section('title', 'Unsubscribe')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4 bg-surface-dark">
    <div class="max-w-md w-full text-center">
        @if($success)
            <h1 class="text-2xl font-bold text-white mb-3">You've been unsubscribed</h1>
            <p class="text-slate-400">You will no longer receive newsletter emails from us. You can resubscribe at any time.</p>
        @else
            <h1 class="text-2xl font-bold text-white mb-3">Link not found</h1>
            <p class="text-slate-400">This unsubscribe link is invalid or has already been used.</p>
        @endif
    </div>
</div>
@endsection
