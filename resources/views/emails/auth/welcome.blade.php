@extends('emails.layouts.base')

@section('body')

<h1>Welcome to {{ $appName }}! 🎉</h1>

<p>Hi <strong>{{ $user->first_name ?? $user->name }}</strong>,</p>

<p>
  We're thrilled to have you on board. Your account has been created successfully —
  you're just one step away from getting full access.
</p>

<div class="hb" style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.20);border-radius:14px;padding:20px 24px;margin:24px 0;">
  <p style="margin:0 0 10px;font-size:14px;font-weight:700;color:#ffffff;">📋 What happens next?</p>
  <p style="margin:0;font-size:14px;color:rgba(255,255,255,0.60);line-height:1.8;">
    <span style="color:#a78bfa;font-weight:600;">①</span> &nbsp;Check your inbox for the 6-digit verification code<br>
    <span style="color:#a78bfa;font-weight:600;">②</span> &nbsp;Enter the code on the verification screen to activate your account<br>
    <span style="color:#a78bfa;font-weight:600;">③</span> &nbsp;You're signed in — start your learning journey
  </p>
</div>

<p>
  Once verified, you'll have full access to all courses, live sessions with expert tutors,
  AI-powered progress tracking, and much more.
</p>

<div class="dv" style="height:1px;background:rgba(255,255,255,0.07);margin:28px 0;"></div>

<p style="font-size:13px;color:rgba(255,255,255,0.30);margin:0;">
  Didn't create this account? No action is needed — this account will be automatically
  removed if not verified within 72 hours.
  Questions? <a href="mailto:{{ config('mail.from.address') }}" style="color:rgba(99,102,241,0.80);">Contact support</a>.
</p>

@endsection
