<?php

namespace App\Exports;

use App\Actions\Reports\BuildWithdrawalsReportAction;
use App\Enums\WithdrawalStatus;
use App\Models\Branch;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class WithdrawalsReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
        private readonly ?WithdrawalStatus $status = null,
    ) {}

    public function collection()
    {
        return app(BuildWithdrawalsReportAction::class)
            ->execute($this->branch, $this->from, $this->to, $this->status)['requests'];
    }

    public function headings(): array
    {
        return ['Requested', 'Account', 'Customer', 'Status', 'Requested By', 'Approved By', 'Penalty', 'Amount'];
    }

    /**
     * @param  WithdrawalRequest  $request
     */
    public function map($request): array
    {
        return [
            $request->created_at->format('Y-m-d H:i'),
            $request->savingsAccount?->account_number,
            $request->customer?->fullName(),
            $request->status->value,
            $request->requestedBy?->name,
            $request->approvedBy?->name,
            Money::format($request->penalty_amount ?? 0),
            Money::format($request->amount),
        ];
    }
}
