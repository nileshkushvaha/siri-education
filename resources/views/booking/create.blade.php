@extends('layouts.frontend')

@section('title', 'Book a Session')
@section('meta_description', 'Book a session with a multi-step booking wizard.')

@if($turnstile['enabled'])
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    @endpush
@endif

@section('content')
    <livewire:frontend.booking.booking-wizard />
@endsection
