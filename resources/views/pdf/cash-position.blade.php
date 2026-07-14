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
        .totals { margin-top: 16px; font-weight: bold; text-align: right; }
    </style>
</head>
<body>
    <h1>Cash Position</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>Held By</th>
                <th class="amount">Cash In Hand</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td>{{ $account->name }}</td>
                    <td>{{ $account->accountable_type === \App\Models\User::class ? 'Agent' : 'Branch Office' }}</td>
                    <td class="amount">{{ \App\Support\Money::format($account->balance) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">No cash accounts for this branch yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">Total Cash In Branch: {{ \App\Support\Money::format($total) }}</div>
</body>
</html>
