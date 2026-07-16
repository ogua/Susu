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
    <h1>Agent Performance &amp; Commissions</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Agent</th>
                <th class="amount">Days Worked</th>
                <th class="amount">Collections</th>
                <th class="amount">Collections Total</th>
                <th class="amount">Variance</th>
                <th class="amount">Unreconciled Days</th>
                <th class="amount">Commission</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['agent'] }}</td>
                    <td class="amount">{{ $row['days_worked'] }}</td>
                    <td class="amount">{{ $row['collections_count'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['collections_total']) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['variance_total']) }}</td>
                    <td class="amount">{{ $row['unreconciled_days'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['commission_total']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No agent activity in this period.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="amount"></td>
                <td class="amount">{{ $totals['collections_count'] }}</td>
                <td class="amount">{{ \App\Support\Money::format($totals['collections_total']) }}</td>
                <td class="amount">{{ \App\Support\Money::format($totals['variance_total']) }}</td>
                <td class="amount"></td>
                <td class="amount">{{ \App\Support\Money::format($totals['commission_total']) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
