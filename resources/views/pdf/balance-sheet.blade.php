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
        .amount { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 2px solid #999; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-weight: bold; }
        .ok { background: #e6f6ec; color: #147a3d; }
        .bad { background: #fdeaea; color: #b32222; }
    </style>
</head>
<body>
    <h1>Balance Sheet (Statement of Financial Position)</h1>
    <div class="meta">
        <div>{{ $company->name }}</div>
        <div>As at: {{ $asAt->toDateString() }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
        <div style="margin-top:6px;">
            <span class="badge {{ $isBalanced ? 'ok' : 'bad' }}">{{ $isBalanced ? 'Balanced' : 'Out of balance' }}</span>
        </div>
    </div>

    @foreach ([['Assets', $assetRows, $totalAssets], ['Liabilities', $liabilityRows, $totalLiabilities], ['Equity', $equityRows, $totalEquity]] as [$title, $rows, $total])
        <h2>{{ $title }}</h2>
        <table>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['code'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="amount">{{ \App\Support\Money::format($row['amount']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">None.</td></tr>
                @endforelse
                <tr class="total">
                    <td colspan="2">Total {{ $title }}</td>
                    <td class="amount">{{ \App\Support\Money::format($total) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <h2>Retained Earnings</h2>
    <table>
        <tbody>
            <tr class="total">
                <td colspan="2">Retained Earnings (income − expenses to date)</td>
                <td class="amount">{{ \App\Support\Money::format($retainedEarnings) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
