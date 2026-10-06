<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Subscription payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; background: #f5f5f7; margin: 0; padding: 40px 16px; color: #1a1a1a; }
        .card { max-width: 460px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); text-align: center; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        .muted { color: #666; font-size: 14px; }
        .fail { color: #b3261e; }
        @media (prefers-color-scheme: dark) {
            body { background: #111; color: #eee; }
            .card { background: #1c1c1e; box-shadow: none; }
            .muted { color: #aaa; }
        }
    </style>
</head>
<body>
    <div class="card">
        @if ($paid)
            <h1>Payment received</h1>
            <p class="muted">Invoice {{ $number }} is paid — thank you. Close this window and return to the app.</p>
        @else
            <h1 class="fail">Payment not completed</h1>
            <p class="muted">No charge was confirmed. Close this window and try again from the app.</p>
        @endif
    </div>
</body>
</html>
