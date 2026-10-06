<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Activate OguaFinance Desktop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; background: #f5f5f7; margin: 0; padding: 40px 16px; color: #1a1a1a; }
        .card { max-width: 420px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .price { font-size: 28px; font-weight: 700; margin: 12px 0 4px; }
        .price-sub { color: #666; font-size: 13px; margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 4px; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #ccc;
            border-radius: 8px; font-size: 14px; }
        button { width: 100%; margin-top: 22px; padding: 12px; border: none; border-radius: 8px;
            background: #208AEF; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .errors { background: #fdecea; color: #b3261e; padding: 10px 12px; border-radius: 8px;
            font-size: 13px; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Activate OguaFinance Desktop</h1>
        <div class="price">{{ $currency }} {{ number_format($price / 100, 2) }}</div>
        <div class="price-sub">Valid for {{ $durationDays }} days from activation</div>

        @if ($errors->any())
            <div class="errors">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('license.checkout') }}">
            @csrf
            <label for="install_id">Install ID</label>
            <input type="text" id="install_id" name="install_id" value="{{ old('install_id', $installId) }}" required>

            <label for="customer_name">Full name</label>
            <input type="text" id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required>

            <label for="customer_email">Email</label>
            <input type="email" id="customer_email" name="customer_email" value="{{ old('customer_email') }}" required>

            <label for="customer_phone">Phone (for SMS delivery, optional)</label>
            <input type="tel" id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}">

            <button type="submit">Pay and get activation key</button>
        </form>
    </div>
</body>
</html>
