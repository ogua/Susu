<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SusuApp Desktop Activation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; background: #f5f5f7; margin: 0; padding: 40px 16px; color: #1a1a1a; }
        .card { max-width: 460px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); text-align: center; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        .key-box { background: #f3f3f3; border-radius: 6px; padding: 12px; font-family: monospace;
            font-size: 12px; word-break: break-all; margin: 16px 0; text-align: left; }
        .muted { color: #666; font-size: 13px; }
        .fail { color: #b3261e; }
    </style>
</head>
<body>
    <div class="card">
        @if ($sale === null)
            <h1 class="fail">We couldn't find that payment</h1>
            <p class="muted">If you completed a payment, check your email — the key is sent there too.</p>
        @elseif ($sale->status->value === 'issued')
            <h1>Payment successful</h1>
            <p>Your activation key:</p>
            <div class="key-box">{{ $sale->license_key }}</div>
            <p class="muted">Also sent to {{ $sale->customer_email }}{{ $sale->customer_phone ? ' and by SMS' : '' }}. Paste it into the "Activate SusuApp Desktop" screen.</p>
        @elseif ($sale->status->value === 'failed')
            <h1 class="fail">Payment failed</h1>
            <p class="muted">No charge was completed. Please try again from the desktop app.</p>
        @else
            <h1>Payment pending</h1>
            <p class="muted">We're still confirming your payment — check your email shortly for the activation key.</p>
        @endif
    </div>
</body>
</html>
