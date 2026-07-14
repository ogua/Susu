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
    </style>
</head>
<body>
    <h1>Defaulters Report</h1>
    <div class="meta">
        <div>{{ $branch->name }}</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Loan #</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Agent</th>
                <th class="amount">Days Overdue</th>
                <th class="amount">Amount Due</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($installments as $installment)
                <tr>
                    <td>{{ $installment->loan->loan_number }}</td>
                    <td>{{ $installment->loan->customer->fullName() }}</td>
                    <td>{{ $installment->loan->customer->phone }}</td>
                    <td>{{ $installment->loan->agent->name }}</td>
                    <td class="amount">{{ (int) $installment->due_date->diffInDays(now()) }}</td>
                    <td class="amount">{{ \App\Support\Money::format($installment->remaining()) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No defaulters — every installment is current.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
