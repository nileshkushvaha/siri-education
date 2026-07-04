@php
    $meta = $submission->meta ?? [];
@endphp

<h2>{{ $type->label() }}</h2>

<p><strong>Name:</strong> {{ $submission->name }}</p>
@if($submission->email)
    <p><strong>Email:</strong> {{ $submission->email }}</p>
@endif
@if($submission->phone)
    <p><strong>Phone:</strong> {{ $submission->phone }}</p>
@endif
@if($submission->subject)
    <p><strong>Subject:</strong> {{ $submission->subject }}</p>
@endif

<hr>

<p><strong>Message:</strong></p>
<p>{{ $submission->message }}</p>

@if(!empty($meta))
    <hr>
    <h3>Additional details</h3>
    <ul>
        @foreach($meta as $key => $value)
            <li><strong>{{ \Illuminate\Support\Str::headline($key) }}:</strong> {{ is_scalar($value) ? (string) $value : json_encode($value) }}</li>
        @endforeach
    </ul>
@endif

<hr>

<p style="color:#888;font-size:12px;">
    Submitted {{ $submission->created_at->toDayDateTimeString() }}
    @if($submission->ip_address) from {{ $submission->ip_address }} @endif
</p>
