@extends('layouts.error')

@section('title', 'Request Error')
@section('meta_description', 'The request could not be completed.')
@section('code', method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : '4xx')
@section('error-title', 'Request could not be completed')
@section('error-message', $exception->getMessage() ?: 'Please check the link or try again from the previous page.')
