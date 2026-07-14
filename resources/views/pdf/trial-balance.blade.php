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
        .totals { margin-top: 16px; font-weight: bold; }
        .totals div { margin-bottom: 4px; }
    </style>
</head>
<body>
    <h1>Trial Balance</h1>
    <div class="meta">
        <div>{{ $company->name }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th class="amount">Debit</th>
                <th class="amount">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($accounts as $account)
                <tr>
                    <td>{{ $account->code }}</td>
                    <td>{{ $account->name }}</td>
                    <td>{{ $account->type->value }}</td>
                    <td class="amount">{{ $account->type->normalBalance() === 'debit' ? \App\Support\Money::format($account->balance) : '' }}</td>
                    <td class="amount">{{ $account->type->normalBalance() === 'credit' ? \App\Support\Money::format($account->balance) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div>Total Debits: {{ \App\Support\Money::format($totalDebits) }}</div>
        <div>Total Credits: {{ \App\Support\Money::format($totalCredits) }}</div>
        <div>{{ $totalDebits === $totalCredits ? 'Balanced' : 'OUT OF BALANCE' }}</div>
    </div>
</body>
</html>
