<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 14px; color: #1a1a1a; line-height: 1.5; }
        .key-box { background: #f3f3f3; border-radius: 6px; padding: 12px; font-family: monospace;
            font-size: 12px; word-break: break-all; margin: 16px 0; }
        .meta { color: #444; font-size: 13px; }
    </style>
</head>
<body>
    <p>Hi {{ $sale->customer_name }},</p>

    <p>Thanks for your payment. Here is your OguaFinance Desktop activation key:</p>

    <div class="key-box">{{ $sale->license_key }}</div>

    <p class="meta">
        Install ID: {{ $sale->install_id }}<br>
        Valid until: {{ $sale->expires_at?->toFormattedDateString() }}
    </p>

    <p>Paste this key into the "Activate OguaFinance Desktop" screen and click Activate.</p>

    <p>If you didn't request this, please ignore this email.</p>
</body>
</html>
