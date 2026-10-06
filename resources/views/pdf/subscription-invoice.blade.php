<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #555; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .right { text-align: right; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.lines th, table.lines td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; }
        table.lines th { background: #f3f3f3; }
        .amount { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 2px solid #999; }
        .stamp { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; }
        .paid { color: #15803d; border: 2px solid #15803d; }
        .unpaid { color: #b45309; border: 2px solid #b45309; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $isReceipt ? 'Receipt' : 'Invoice' }}</h1>
                <div class="muted">{{ config('app.name') }}</div>
                @if (config('platform.support_email'))
                    <div class="muted">{{ config('platform.support_email') }}</div>
                @endif
                @if (config('platform.support_phone'))
                    <div class="muted">{{ config('platform.support_phone') }}</div>
                @endif
            </td>
            <td class="right">
                <div><strong>{{ $invoice->number }}</strong></div>
                <div class="muted">Issued {{ $invoice->created_at->format('d M Y') }}</div>
                <div class="muted">Due {{ $invoice->due_at->format('d M Y') }}</div>
                <div style="margin-top: 8px;">
                    <span class="stamp {{ $isReceipt ? 'paid' : 'unpaid' }}">{{ $isReceipt ? 'PAID' : strtoupper($invoice->status->value) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div><strong>Billed to</strong></div>
    <div>{{ $invoice->company->name }}</div>
    @if ($invoice->company->address)
        <div class="muted">{{ $invoice->company->address }}</div>
    @endif
    <div class="muted">{{ $invoice->company->contact_email }} · {{ $invoice->company->contact_phone }}</div>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th>Period</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->plan->name }} plan subscription</td>
                <td>{{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</td>
                <td class="amount">{{ \App\Support\Money::format($invoice->amount, $invoice->currency) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="2">{{ $isReceipt ? 'Total paid' : 'Total due' }}</td>
                <td class="amount">{{ \App\Support\Money::format($invoice->amount, $invoice->currency) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($isReceipt)
        <p class="muted" style="margin-top: 16px;">
            Paid {{ $invoice->paid_at?->format('d M Y H:i') }}
            by {{ str_replace('_', ' ', (string) $invoice->payment_method) }}
            @if ($invoice->payment_reference) (ref. {{ $invoice->payment_reference }}) @endif.
            Thank you.
        </p>
    @else
        <p class="muted" style="margin-top: 16px;">
            Pay from the Subscription page in the admin panel or the app. Unpaid invoices more than
            {{ config('billing.suspend_after_days') }} days past their due date lead to the account being suspended.
        </p>
    @endif
</body>
</html>
