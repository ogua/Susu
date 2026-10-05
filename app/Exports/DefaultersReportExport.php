<?php

namespace App\Exports;

use App\Actions\Reports\BuildDefaultersReportAction;
use App\Models\Branch;
use App\Models\GroupLoanInstallment;
use App\Models\LoanInstallment;
use App\Support\Money;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DefaultersReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Branch $branch) {}

    public function collection()
    {
        $report = app(BuildDefaultersReportAction::class);

        return $report->execute($this->branch)->toBase()->concat($report->groupLoans($this->branch));
    }

    public function headings(): array
    {
        return ['Loan #', 'Type', 'Customer', 'Phone', 'Agent', 'Days Overdue', 'Amount Due'];
    }

    /**
     * @param  LoanInstallment|GroupLoanInstallment  $installment
     */
    public function map($installment): array
    {
        $loan = $installment instanceof GroupLoanInstallment ? $installment->groupLoan : $installment->loan;

        return [
            $loan->loan_number,
            $installment instanceof GroupLoanInstallment ? 'Group loan' : 'Loan',
            $loan->customer->fullName(),
            $loan->customer->phone,
            $loan->agent?->name,
            (int) $installment->due_date->diffInDays(now()),
            Money::format($installment->remaining()),
        ];
    }
}
