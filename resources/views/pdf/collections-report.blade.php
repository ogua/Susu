<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 14px; margin: 18px 0 4px; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
    </style>
</head>
<body>
    <h1>Collections Report</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Agent</th>
                <th>Description</th>
                <th>Method</th>
                <th>Status</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->recorded_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $entry->reference }}</td>
                    <td>{{ $entry->recordedBy?->name ?? 'Unknown' }}</td>
                    <td>{{ $entry->description }}</td>
                    <td>{{ $entry->payment_method->value }}</td>
                    <td>{{ $entry->status->value }}</td>
                    <td class="amount">{{ \App\Support\Money::format((int) $entry->amount_sum) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No collections recorded in this period.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">Total ({{ $totalCount }} collection{{ $totalCount === 1 ? '' : 's' }})</td>
                <td class="amount">{{ \App\Support\Money::format($totalAmount) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>By Agent</h2>
    <table>
        <thead>
            <tr>
                <th>Agent</th>
                <th class="amount">Collections</th>
                <th class="amount">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($agentSubtotals as $row)
                <tr>
                    <td>{{ $row['agent'] }}</td>
                    <td class="amount">{{ $row['count'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
