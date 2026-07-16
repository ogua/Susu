<?php

namespace App\Exports;

use App\Actions\Reports\BuildLoanPortfolioReportAction;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LoanPortfolioExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
        private readonly ?LoanStatus $status = null,
    ) {}

    public function collection()
    {
        return app(BuildLoanPortfolioReportAction::class)
            ->execute($this->branch, $this->from, $this->to, $this->status)['loans'];
    }

    public function headings(): array
    {
        return ['Loan #', 'Customer', 'Agent', 'Product', 'Status', 'Applied', 'Disbursed', 'Principal', 'Repayable', 'Outstanding'];
    }

    /**
     * @param  Loan  $loan
     */
    public function map($loan): array
    {
        return [
            $loan->loan_number,
            $loan->customer->fullName(),
            $loan->agent?->name,
            $loan->loanProduct?->name,
            $loan->status->value,
            $loan->applied_at?->format('Y-m-d'),
            $loan->disbursed_at?->format('Y-m-d'),
            Money::format($loan->principal_amount),
            Money::format($loan->total_repayable ?? 0),
            Money::format($loan->outstanding_balance ?? 0),
        ];
    }
}
