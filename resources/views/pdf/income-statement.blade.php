<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 14px; margin: 18px 0 4px; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 2px solid #999; }
        .net { margin-top: 16px; font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Income Statement (Profit &amp; Loss)</h1>
    <div class="meta">
        <div>{{ $company->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <h2>Income</h2>
    <table>
        <tbody>
            @forelse ($incomeRows as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No income in this period.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">Total Income</td>
                <td class="amount">{{ \App\Support\Money::format($totalIncome) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Expenses</h2>
    <table>
        <tbody>
            @forelse ($expenseRows as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No expenses in this period.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">Total Expenses</td>
                <td class="amount">{{ \App\Support\Money::format($totalExpenses) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="net">Net Income: {{ \App\Support\Money::format($netIncome) }}</p>
</body>
</html>
