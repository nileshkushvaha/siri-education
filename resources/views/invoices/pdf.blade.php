<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 6px 0; vertical-align: top; }
        .label { color: #666; width: 40%; }
        .total { font-size: 16px; font-weight: bold; margin-top: 16px; }
        .divider { border-top: 1px solid #ddd; margin: 16px 0; }
    </style>
</head>
<body>
    <h1>{{ $invoice->organization_name ?? config('app.name') }}</h1>
    <p class="muted">
        {{ $invoice->organization_address }}<br>
        @if($invoice->organization_support_email) {{ $invoice->organization_support_email }} @endif
        @if($invoice->organization_support_phone) &middot; {{ $invoice->organization_support_phone }} @endif
    </p>

    <div class="divider"></div>

    <table>
        <tr>
            <td class="label">Invoice Number</td>
            <td>{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
            <td class="label">Issued</td>
            <td>{{ $invoice->issued_at->format('F j, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Billed To</td>
            <td>{{ $invoice->student_name }}@if($invoice->billing_country), {{ $invoice->billing_country }}@endif</td>
        </tr>
        <tr>
            <td class="label">Service</td>
            <td>{{ $invoice->service_description }}</td>
        </tr>
        @if($invoice->booking_reference)
        <tr>
            <td class="label">Booking Reference</td>
            <td>{{ $invoice->booking_reference }}</td>
        </tr>
        @endif
        @if($invoice->wallet_recharge_reference)
        <tr>
            <td class="label">Wallet Recharge Reference</td>
            <td>{{ $invoice->wallet_recharge_reference }}</td>
        </tr>
        @endif
        @if($invoice->package_purchase_reference)
        <tr>
            <td class="label">Package Reference</td>
            <td>{{ $invoice->package_purchase_reference }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Payment Date</td>
            <td>{{ $invoice->payment_date->format('F j, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Payment Reference</td>
            <td>{{ $invoice->payment_reference }}</td>
        </tr>
    </table>

    <p class="total">Amount Paid: {{ \App\Support\MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code) }}</p>

    <div class="divider"></div>
    <p class="muted">This receipt is issued for the payment described above and is immutable once generated.</p>
</body>
</html>
