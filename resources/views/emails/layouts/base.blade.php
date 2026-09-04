<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="x-apple-disable-message-reformatting">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>{{ $subject ?? config('app.name') }}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
  /* Reset */
  *, *::before, *::after { box-sizing: border-box; }
  body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
  img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
  body { margin: 0; padding: 0; width: 100%; background-color: #080B20; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }

  /* Wrapper */
  .email-wrapper { width: 100%; background-color: #080B20; padding: 48px 16px; }
  .email-container { max-width: 600px; margin: 0 auto; }

  /* Header */
  .email-header {
    text-align: center;
    padding: 0 0 32px;
  }
  .logo-wrap {
    display: inline-flex;
    align-items: center;
    text-decoration: none;
    gap: 10px;
  }
  .logo-wrap img { display:block; width:240px; height:auto; }

  /* Card */
  .email-card {
    background: linear-gradient(145deg, rgba(255,255,255,0.065) 0%, rgba(255,255,255,0.03) 100%);
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 24px;
    padding: 48px 44px;
    position: relative;
    overflow: hidden;
  }
  .card-accent {
    display: block;
    height: 3px;
    background: linear-gradient(90deg, #6366F1, #8B5CF6, #A78BFA);
    border-radius: 3px 3px 0 0;
    margin: -48px -44px 40px;
  }

  /* Typography */
  h1 {
    font-size: 26px;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.3;
    margin: 0 0 16px;
    letter-spacing: -0.3px;
  }
  h2 {
    font-size: 18px;
    font-weight: 600;
    color: #ffffff;
    margin: 0 0 12px;
  }
  p {
    font-size: 15px;
    line-height: 1.75;
    color: rgba(255,255,255,0.65);
    margin: 0 0 16px;
  }
  p:last-child { margin-bottom: 0; }
  a { color: #818cf8; text-decoration: none; }
  strong { color: #ffffff; font-weight: 600; }

  /* Button */
  .btn-wrap { text-align: center; margin: 36px 0; }
  .btn {
    display: inline-block;
    padding: 16px 44px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: 0.1px;
    text-decoration: none;
    text-align: center;
    color: #ffffff;
    background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
    box-shadow: 0 4px 24px rgba(99,102,241,0.45), 0 1px 0 rgba(255,255,255,0.1) inset;
  }
  .btn:hover { background: linear-gradient(135deg, #4338CA 0%, #6D28D9 100%); }
  .btn-danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    box-shadow: 0 4px 20px rgba(220,38,38,0.40);
  }

  /* Highlight Box */
  .hb {
    background: rgba(99,102,241,0.08);
    border: 1px solid rgba(99,102,241,0.20);
    border-radius: 14px;
    padding: 20px 24px;
    margin: 24px 0;
  }
  .hb p { font-size: 14px; color: rgba(255,255,255,0.60); margin: 0; line-height: 1.7; }
  .hb strong { color: rgba(255,255,255,0.90); }

  /* Alert Box */
  .alert-box {
    background: rgba(239,68,68,0.08);
    border: 1px solid rgba(239,68,68,0.20);
    border-radius: 14px;
    padding: 18px 22px;
    margin: 24px 0;
  }
  .alert-box p { font-size: 14px; color: rgba(255,255,255,0.65); margin: 0; }

  /* Success Box */
  .success-box {
    background: rgba(16,185,129,0.08);
    border: 1px solid rgba(16,185,129,0.20);
    border-radius: 14px;
    padding: 20px 24px;
    margin: 24px 0;
  }

  /* Divider */
  .dv { height: 1px; background: rgba(255,255,255,0.07); margin: 32px 0; }

  /* URL fallback */
  .url-box {
    background: rgba(0,0,0,0.25);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    padding: 14px 18px;
    margin: 16px 0;
    word-break: break-all;
    font-size: 12px;
    color: rgba(99,102,241,0.85);
    font-family: 'Courier New', Courier, monospace;
  }

  /* Small / meta text */
  .sm { font-size: 13px; color: rgba(255,255,255,0.32); line-height: 1.65; margin: 0; }
  .link { color: rgba(99,102,241,0.85); word-break: break-all; font-size: 13px; }

  /* Footer */
  .email-footer {
    text-align: center;
    padding: 32px 20px 8px;
  }
  .footer-divider { height: 1px; background: rgba(255,255,255,0.05); margin: 0 0 28px; }
  .footer-logo { font-size: 16px; font-weight: 700; color: rgba(255,255,255,0.4); margin-bottom: 10px; }
  .footer-links { margin-bottom: 14px; }
  .footer-links a { font-size: 12px; color: rgba(255,255,255,0.25); text-decoration: none; margin: 0 10px; }
  .footer-links a:hover { color: rgba(255,255,255,0.45); }
  .footer-copy { font-size: 11px; color: rgba(255,255,255,0.18); line-height: 1.7; }

  /* Responsive */
  @media (max-width: 640px) {
    .email-wrapper { padding: 24px 12px; }
    .email-card { padding: 32px 24px; }
    .card-accent { margin: -32px -24px 32px; }
    h1 { font-size: 22px; }
    .btn { padding: 14px 32px; font-size: 14px; }
  }
</style>
</head>
<body>
<div class="email-wrapper">
  <div class="email-container">

    {{-- ── Header / Logo ──────────────────────────────────────── --}}
    <div class="email-header">
      <a href="{{ $appUrl ?? config('app.url') }}" class="logo-wrap" style="text-decoration:none;display:inline-flex;align-items:center;gap:10px;">
        <img src="{{ asset('images/brand/siri-logo-dark@2x.png') }}" width="240" height="93" alt="{{ $appName ?? config('app.name') }}" style="display:block;width:240px;height:auto;border:0;outline:none;text-decoration:none;">
      </a>
    </div>

    {{-- ── Email Card ──────────────────────────────────────────── --}}
    <div class="email-card">
      <span class="card-accent" style="display:block;height:3px;background:linear-gradient(90deg,#6366F1,#8B5CF6,#A78BFA);border-radius:3px 3px 0 0;margin:-48px -44px 40px;"></span>

      @yield('body')

    </div>

    {{-- ── Footer ──────────────────────────────────────────────── --}}
    <div class="email-footer">
      <div class="footer-divider" style="height:1px;background:rgba(255,255,255,0.05);margin-bottom:24px;"></div>
      <p class="footer-logo" style="font-size:15px;font-weight:700;color:rgba(255,255,255,0.35);margin-bottom:12px;">{{ $appName ?? config('app.name') }}</p>
      <div class="footer-links" style="margin-bottom:14px;">
        <a href="{{ $appUrl ?? config('app.url') }}" style="font-size:12px;color:rgba(255,255,255,0.25);text-decoration:none;margin:0 8px;">Home</a>
        <a href="{{ ($appUrl ?? config('app.url')) }}/profile" style="font-size:12px;color:rgba(255,255,255,0.25);text-decoration:none;margin:0 8px;">My Account</a>
        <a href="mailto:{{ config('mail.from.address') }}" style="font-size:12px;color:rgba(255,255,255,0.25);text-decoration:none;margin:0 8px;">Support</a>
      </div>
      <p class="footer-copy" style="font-size:11px;color:rgba(255,255,255,0.18);line-height:1.7;margin:0;">
        © {{ date('Y') }} {{ $appName ?? config('app.name') }}. All rights reserved.<br>
        You received this email because you have an account with us.<br>
        This is an automated message — please do not reply directly to this email.
      </p>
    </div>

  </div>
</div>
</body>
</html>
