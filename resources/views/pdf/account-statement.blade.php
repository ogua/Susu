<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { margin-bottom: 16px; color: #444; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        .closing { margin-top: 16px; font-weight: bold; text-align: right; }
    </style>
</head>
<body>
    <h1>Account Statement</h1>
    <div class="meta">
        <div><strong>{{ $account->customer->fullName() }}</strong> &middot; {{ $account->customer->phone }}</div>
        <div>Account: {{ $account->account_number }} ({{ $account->product->name }})</div>
        <div>Branch: {{ $account->branch->name }}</div>
        <div>
            Period:
            {{ $from?->toDateString() ?? 'account opening' }}
            &ndash;
            {{ $to?->toDateString() ?? 'today' }}
        </div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="amount">Deposit</th>
                <th class="amount">Withdrawal</th>
                <th class="amount">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['date']->toDateString() }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="amount">{{ $row['credit'] > 0 ? \App\Support\Money::format($row['credit']) : '' }}</td>
                    <td class="amount">{{ $row['debit'] > 0 ? \App\Support\Money::format($row['debit']) : '' }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['balance']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No transactions in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="closing">Closing Balance: {{ \App\Support\Money::format($closingBalance) }}</div>
</body>
</html>
