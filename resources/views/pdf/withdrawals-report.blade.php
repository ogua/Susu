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
    <h1>Withdrawals Report</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}@if ($status) · Status: {{ $status->value }} @endif</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <h2>Totals by Status</h2>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th class="amount">Requests</th>
                <th class="amount">Penalties</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statusTotals as $row)
                <tr>
                    <td>{{ $row['status']->value }}</td>
                    <td class="amount">{{ $row['count'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['penalty']) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="amount">{{ $requests->count() }}</td>
                <td class="amount">{{ \App\Support\Money::format($totalPenalty) }}</td>
                <td class="amount">{{ \App\Support\Money::format($totalAmount) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Requests</h2>
    <table>
        <thead>
            <tr>
                <th>Requested</th>
                <th>Account</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Requested By</th>
                <th>Approved By</th>
                <th class="amount">Penalty</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $request)
                <tr>
                    <td>{{ $request->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $request->savingsAccount?->account_number }}</td>
                    <td>{{ $request->customer?->fullName() }}</td>
                    <td>{{ $request->status->value }}</td>
                    <td>{{ $request->requestedBy?->name }}</td>
                    <td>{{ $request->approvedBy?->name }}</td>
                    <td class="amount">{{ \App\Support\Money::format($request->penalty_amount ?? 0) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($request->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No withdrawal requests in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
