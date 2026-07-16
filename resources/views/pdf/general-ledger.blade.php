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
        .opening td { font-style: italic; background: #fafafa; }
    </style>
</head>
<body>
    <h1>General Ledger @if ($mode === 'detailed') — {{ $account->code }} {{ $account->name }} @endif</h1>
    <div class="meta">
        <div>{{ $company->name }}</div>
        <div>Period: {{ $from?->toDateString() ?? 'Beginning' }} – {{ $to?->toDateString() ?? 'Today' }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    @if ($mode === 'detailed')
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="amount">Debit</th>
                    <th class="amount">Credit</th>
                    <th class="amount">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="opening">
                    <td colspan="6">Opening balance</td>
                    <td class="amount">{{ \App\Support\Money::format($openingBalance) }}</td>
                </tr>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['line']->entry->recorded_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $row['line']->entry->reference }}</td>
                        <td>{{ $row['line']->entry->type->value }}</td>
                        <td>{{ $row['line']->memo ?? $row['line']->entry->description }}</td>
                        <td class="amount">{{ $row['line']->debit > 0 ? \App\Support\Money::format($row['line']->debit) : '' }}</td>
                        <td class="amount">{{ $row['line']->credit > 0 ? \App\Support\Money::format($row['line']->credit) : '' }}</td>
                        <td class="amount">{{ \App\Support\Money::format($row['running']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">No transactions in this period.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Totals / Closing balance</td>
                    <td class="amount">{{ \App\Support\Money::format($totalDebits) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($totalCredits) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($closingBalance) }}</td>
                </tr>
            </tfoot>
        </table>
    @else
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="amount">Transactions</th>
                    <th class="amount">Debits</th>
                    <th class="amount">Credits</th>
                    <th class="amount">Net Movement</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['account']->code }}</td>
                        <td>{{ $row['account']->name }}</td>
                        <td>{{ $row['account']->type->value }}</td>
                        <td class="amount">{{ $row['txn_count'] }}</td>
                        <td class="amount">{{ \App\Support\Money::format($row['debit_total']) }}</td>
                        <td class="amount">{{ \App\Support\Money::format($row['credit_total']) }}</td>
                        <td class="amount">{{ \App\Support\Money::format($row['net']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">No accounts.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Totals</td>
                    <td class="amount">{{ \App\Support\Money::format($totalDebits) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($totalCredits) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
