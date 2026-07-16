<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
    </style>
</head>
<body>
    <h1>Customer Balances</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>As at: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Account #</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Product</th>
                <th>Agent</th>
                <th>Status</th>
                <th class="amount">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td>{{ $account->account_number }}</td>
                    <td>{{ $account->customer->fullName() }}</td>
                    <td>{{ $account->customer->phone }}</td>
                    <td>{{ $account->product?->name }}</td>
                    <td>{{ $account->agent?->name }}</td>
                    <td>{{ $account->status->value }}</td>
                    <td class="amount">{{ \App\Support\Money::format($account->balance) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No open savings accounts in this branch.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">Total ({{ $accounts->count() }} account{{ $accounts->count() === 1 ? '' : 's' }})</td>
                <td class="amount">{{ \App\Support\Money::format($totalBalance) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
