<?php

namespace App\Exports;

use App\Actions\Reports\BuildIncomeStatementAction;
use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class IncomeStatementExport implements FromCollection, WithHeadings
{
    public function __construct(
        private readonly Company $company,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        $result = app(BuildIncomeStatementAction::class)->execute($this->company, $this->from, $this->to);

        $rows = collect([['INCOME', '', '']]);

        foreach ($result['incomeRows'] as $row) {
            $rows->push([$row['code'], $row['name'], Money::format($row['amount'])]);
        }

        $rows->push(['', 'Total Income', Money::format($result['totalIncome'])]);
        $rows->push(['EXPENSES', '', '']);

        foreach ($result['expenseRows'] as $row) {
            $rows->push([$row['code'], $row['name'], Money::format($row['amount'])]);
        }

        $rows->push(['', 'Total Expenses', Money::format($result['totalExpenses'])]);
        $rows->push(['', 'Net Income', Money::format($result['netIncome'])]);

        return $rows;
    }

    public function headings(): array
    {
        return ['Code', 'Account', 'Amount'];
    }
}
