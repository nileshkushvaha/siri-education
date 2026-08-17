{{--
  Branded renderer for every `MailMessage`-based transactional notification.

  Wired in centrally by App\Notifications\Concerns\ConfiguresTransactionalEmail,
  so notification classes keep building stock `->subject()/->greeting()/->line()
  /->action()` messages and get the SIRI shell for free — no per-class HTML, and
  no duplicated layout. A notification that needs bespoke markup simply calls
  `->view(...)` after `configureMailMessage()`; that assignment wins over this
  default.

  Receives Laravel's standard MailMessage payload: $level, $subject, $greeting,
  $introLines, $actionText, $actionUrl, $displayableActionUrl, $outroLines,
  $salutation.
--}}
@extends('emails.layouts.base')

@section('body')

@php
    $accent = match ($level ?? 'info') {
        'error' => '#F87171',
        'success' => '#34D399',
        default => '#A78BFA',
    };
    $buttonStyle = match ($level ?? 'info') {
        'error' => 'background:linear-gradient(135deg,#DC2626 0%,#B91C1C 100%);box-shadow:0 4px 20px rgba(220,38,38,0.40);',
        'success' => 'background:linear-gradient(135deg,#059669 0%,#047857 100%);box-shadow:0 4px 20px rgba(16,185,129,0.40);',
        default => 'background:linear-gradient(135deg,#4F46E5 0%,#7C3AED 100%);box-shadow:0 4px 24px rgba(99,102,241,0.45);',
    };
@endphp

<h1 style="color:#ffffff;font-size:26px;font-weight:700;line-height:1.3;margin:0 0 24px;letter-spacing:-0.3px;">
    {{ $greeting ?? $subject ?? config('app.name') }}
</h1>

@foreach ($introLines as $line)
    <p style="font-size:15px;line-height:1.75;color:rgba(255,255,255,0.65);margin:0 0 16px;">
        @if ($line instanceof \Illuminate\Support\HtmlString){!! $line !!}@else{{ $line }}@endif
    </p>
@endforeach

@isset($actionText)
    <div class="btn-wrap" style="text-align:center;margin:36px 0;">
        <a href="{{ $actionUrl }}" class="btn" style="display:inline-block;padding:16px 44px;border-radius:14px;font-weight:700;font-size:15px;text-decoration:none;color:#ffffff;{{ $buttonStyle }}">
            {{ $actionText }}
        </a>
    </div>
@endisset

@foreach ($outroLines as $line)
    <p style="font-size:15px;line-height:1.75;color:rgba(255,255,255,0.65);margin:0 0 16px;">
        @if ($line instanceof \Illuminate\Support\HtmlString){!! $line !!}@else{{ $line }}@endif
    </p>
@endforeach

<div class="dv" style="height:1px;background:rgba(255,255,255,0.07);margin:32px 0;"></div>

<p style="font-size:14px;line-height:1.7;color:rgba(255,255,255,0.45);margin:0;">
    @if (! empty($salutation))
        {{ $salutation }}
    @else
        Regards,<br><strong style="color:{{ $accent }};font-weight:600;">The {{ config('app.name') }} Team</strong>
    @endif
</p>

@isset($actionText)
    {{-- Fallback for clients that strip the button. --}}
    <p style="font-size:13px;color:rgba(255,255,255,0.32);line-height:1.65;margin:24px 0 8px;">
        If the &ldquo;{{ $actionText }}&rdquo; button above doesn&rsquo;t work, copy and paste this link into your browser:
    </p>
    <div class="url-box" style="background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 18px;word-break:break-all;font-size:12px;color:rgba(129,140,248,0.85);font-family:'Courier New',Courier,monospace;">
        {{ $displayableActionUrl ?? $actionUrl }}
    </div>
@endisset

@endsection
