<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px 5px; text-align: left; }
        th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        .suspended { color: #b91c1c; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
    </style>
</head>
<body>
    <h1>Company Usage Report</h1>
    <div class="meta">
        <div>All companies on the platform</div>
        <div>Period: {{ $from->toDateString() }} – {{ $to->toDateString() }} (new customers and collections)</div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Company</th>
                <th>Status</th>
                <th class="amount">Branches</th>
                <th class="amount">Staff</th>
                <th class="amount">Admins</th>
                <th class="amount">Customers</th>
                <th class="amount">New</th>
                <th class="amount">Savings Held</th>
                <th class="amount">Loans Outstanding</th>
                <th class="amount">Collections</th>
                <th class="amount">Amount</th>
                <th>Last Transaction</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($companies as $company)
                <tr>
                    <td>{{ $company->name }}</td>
                    <td class="{{ $company->is_active ? '' : 'suspended' }}">{{ $company->is_active ? 'Active' : 'Suspended' }}</td>
                    <td class="amount">{{ $company->branches_count }}</td>
                    <td class="amount">{{ $company->staff_count }}</td>
                    <td class="amount">{{ $company->company_admins_count }}</td>
                    <td class="amount">{{ $company->customers_count }}</td>
                    <td class="amount">{{ $company->new_customers_count }}</td>
                    <td class="amount">{{ \App\Support\Money::format((int) $company->savings_balance) }}</td>
                    <td class="amount">{{ \App\Support\Money::format((int) $company->loans_outstanding) }}</td>
                    <td class="amount">{{ $company->collections_count }}</td>
                    <td class="amount">{{ \App\Support\Money::format((int) $company->collections_amount) }}</td>
                    <td>{{ $company->last_activity_at ? \Carbon\CarbonImmutable::parse($company->last_activity_at)->format('Y-m-d') : 'Never' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12">No companies on the platform yet.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total ({{ $companies->count() }})</td>
                <td class="amount">{{ $companies->sum('branches_count') }}</td>
                <td class="amount">{{ $companies->sum('staff_count') }}</td>
                <td class="amount">{{ $companies->sum('company_admins_count') }}</td>
                <td class="amount">{{ $companies->sum('customers_count') }}</td>
                <td class="amount">{{ $companies->sum('new_customers_count') }}</td>
                <td class="amount">{{ \App\Support\Money::format((int) $companies->sum('savings_balance')) }}</td>
                <td class="amount">{{ \App\Support\Money::format((int) $companies->sum('loans_outstanding')) }}</td>
                <td class="amount">{{ $companies->sum('collections_count') }}</td>
                <td class="amount">{{ \App\Support\Money::format((int) $companies->sum('collections_amount')) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
