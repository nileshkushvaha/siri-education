@extends('layouts.error')

@section('title', '404 Page Not Found')
@section('meta_description', 'The page you are looking for could not be found.')
@section('code', '404')
@section('error-title', 'Page not found')
@section('error-message', "The page you're looking for has moved, was removed, or never existed.")

@section('error-actions')
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
    @if(Route::has('blog.index'))
        <a href="{{ route('blog.index') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
            Browse blog
        </a>
    @endif
@endsection
