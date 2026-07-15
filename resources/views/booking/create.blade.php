@extends('layouts.frontend')

@section('title', 'Book a Session')
@section('meta_description', 'Book a session with a multi-step booking wizard.')

@section('content')
    <livewire:frontend.booking.booking-wizard />
@endsection
