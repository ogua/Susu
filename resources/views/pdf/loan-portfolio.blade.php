<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 14px; margin: 18px 0 4px; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 5px 6px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
    </style>
</head>
<body>
    <h1>Loan Portfolio Report</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Period (applied): {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}@if ($status) · Status: {{ $status->value }} @endif</div>
        <div>Portfolio At Risk: {{ $parPercent }}% ({{ \App\Support\Money::format($atRiskOutstanding) }} at risk)</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <h2>Summary by Status</h2>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th class="amount">Loans</th>
                <th class="amount">Principal</th>
                <th class="amount">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statusSummary as $row)
                <tr>
                    <td>{{ $row['status']->value }}</td>
                    <td class="amount">{{ $row['count'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['principal']) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($row['outstanding']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="amount">{{ $loans->count() }}</td>
                <td class="amount">{{ \App\Support\Money::format($totalPrincipal) }}</td>
                <td class="amount">{{ \App\Support\Money::format($totalOutstanding) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Loans</h2>
    <table>
        <thead>
            <tr>
                <th>Loan #</th>
                <th>Customer</th>
                <th>Agent</th>
                <th>Status</th>
                <th>Applied</th>
                <th>Disbursed</th>
                <th class="amount">Principal</th>
                <th class="amount">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($loans as $loan)
                <tr>
                    <td>{{ $loan->loan_number }}</td>
                    <td>{{ $loan->customer->fullName() }}</td>
                    <td>{{ $loan->agent?->name }}</td>
                    <td>{{ $loan->status->value }}</td>
                    <td>{{ $loan->applied_at?->format('Y-m-d') }}</td>
                    <td>{{ $loan->disbursed_at?->format('Y-m-d') }}</td>
                    <td class="amount">{{ \App\Support\Money::format($loan->principal_amount) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($loan->outstanding_balance ?? 0) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No loans match this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
