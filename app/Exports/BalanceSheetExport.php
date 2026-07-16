<?php

namespace App\Exports;

use App\Actions\Reports\BuildBalanceSheetAction;
use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BalanceSheetExport implements FromCollection, WithHeadings
{
    public function __construct(
        private readonly Company $company,
        private readonly ?CarbonImmutable $asAt = null,
    ) {}

    public function collection()
    {
        $result = app(BuildBalanceSheetAction::class)->execute($this->company, $this->asAt);

        $rows = collect();

        $section = function (string $title, $sectionRows, string $totalLabel, int $total) use ($rows): void {
            $rows->push([$title, '', '']);
            foreach ($sectionRows as $row) {
                $rows->push([$row['code'], $row['name'], Money::format($row['amount'])]);
            }
            $rows->push(['', $totalLabel, Money::format($total)]);
        };

        $section('ASSETS', $result['assetRows'], 'Total Assets', $result['totalAssets']);
        $section('LIABILITIES', $result['liabilityRows'], 'Total Liabilities', $result['totalLiabilities']);
        $section('EQUITY', $result['equityRows'], 'Total Equity', $result['totalEquity']);

        $rows->push(['', 'Retained Earnings', Money::format($result['retainedEarnings'])]);
        $rows->push(['', 'Balanced', $result['isBalanced'] ? 'Yes' : 'No']);

        return $rows;
    }

    public function headings(): array
    {
        return ['Code', 'Account', 'Amount'];
    }
}
