<?php

namespace App\Exports;

use App\Actions\Reports\BuildDefaultersReportAction;
use App\Models\Branch;
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
        return app(BuildDefaultersReportAction::class)->execute($this->branch);
    }

    public function headings(): array
    {
        return ['Loan #', 'Customer', 'Phone', 'Agent', 'Days Overdue', 'Amount Due'];
    }

    /**
     * @param  LoanInstallment  $installment
     */
    public function map($installment): array
    {
        return [
            $installment->loan->loan_number,
            $installment->loan->customer->fullName(),
            $installment->loan->customer->phone,
            $installment->loan->agent->name,
            (int) $installment->due_date->diffInDays(now()),
            Money::format($installment->remaining()),
        ];
    }
}
