@extends('emails.layouts.base')

@section('body')

<h1>Your verification code 🔐</h1>

<p>Hi <strong>{{ $notifiable->first_name ?? $notifiable->name }}</strong>,</p>

<p>
  Use the code below to verify your email address and activate your
  <strong>{{ $appName }}</strong> account.
</p>

<div class="btn-wrap" style="text-align:center;margin:36px 0;">
  <div style="display:inline-block;padding:20px 40px;border-radius:14px;background:rgba(99,102,241,0.12);border:1px solid rgba(99,102,241,0.30);font-family:'Courier New',Courier,monospace;font-size:34px;font-weight:700;letter-spacing:10px;color:#ffffff;">
    {{ $code }}
  </div>
</div>

<div class="hb" style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.20);border-radius:14px;padding:18px 22px;margin:24px 0;">
  <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.55);line-height:1.6;">
    ⏱ &nbsp;This code expires in <strong style="color:#ffffff;">{{ $expiry }} minutes</strong>.
    You can request a new one from the verification screen.
  </p>
</div>

<div class="dv" style="height:1px;background:rgba(255,255,255,0.07);margin:28px 0;"></div>

<p style="font-size:13px;color:rgba(255,255,255,0.30);margin:0;">
  Never share this code with anyone. If you didn't create an account with {{ $appName }},
  you can safely ignore this email — the code will expire on its own.
</p>

@endsection
