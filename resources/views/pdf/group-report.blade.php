<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 5px 6px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
    </style>
</head>
<body>
    <h1>Susu Groups Report</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Group</th>
                <th>Code</th>
                <th>Status</th>
                <th class="amount">Members</th>
                <th class="amount">Round</th>
                <th class="amount">Round Expected</th>
                <th class="amount">Round Collected</th>
                <th class="amount">Paid Out</th>
                <th class="amount">In Period</th>
                <th class="amount">Lifetime</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['group']->name }}</td>
                    <td>{{ $row['group']->code }}</td>
                    <td>{{ $row['group']->status->value }}</td>
                    <td class="amount">{{ $row['members_count'] }}</td>
                    <td class="amount">{{ $row['current_round'] ?? '—' }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['round_expected']) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['round_collected']) }}</td>
                    <td class="amount">{{ $row['rounds_paid_out'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['collected_in_period']) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['lifetime_collected']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">No susu groups in this branch.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="8">Total</td>
                <td class="amount">{{ \App\Support\Money::format($totalCollectedInPeriod) }}</td>
                <td class="amount">{{ \App\Support\Money::format($totalLifetimeCollected) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
