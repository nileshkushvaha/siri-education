{{-- Plain-text counterpart of emails/notification.blade.php. Same MailMessage payload. --}}
{{ $greeting ?? $subject ?? config('app.name') }}
@foreach ($introLines as $line)

{{ $line }}
@endforeach
@isset($actionText)

{{ $actionText }}: {{ $actionUrl }}
@endisset
@foreach ($outroLines as $line)

{{ $line }}
@endforeach

@if (! empty($salutation)){{ $salutation }}@else Regards,
The {{ config('app.name') }} Team
@endif
