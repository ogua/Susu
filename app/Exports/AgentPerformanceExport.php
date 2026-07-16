<?php

namespace App\Exports;

use App\Actions\Reports\BuildAgentPerformanceReportAction;
use App\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AgentPerformanceExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        return app(BuildAgentPerformanceReportAction::class)
            ->execute($this->branch, $this->from, $this->to)['rows'];
    }

    public function headings(): array
    {
        return ['Agent', 'Days Worked', 'Collections', 'Collections Total', 'Variance', 'Unreconciled Days', 'Commission Earned'];
    }

    /**
     * @param  array{agent: string, days_worked: int, collections_total: int, collections_count: int, variance_total: int, unreconciled_days: int, commission_total: int}  $row
     */
    public function map($row): array
    {
        return [
            $row['agent'],
            $row['days_worked'],
            $row['collections_count'],
            Money::format($row['collections_total']),
            Money::format($row['variance_total']),
            $row['unreconciled_days'],
            Money::format($row['commission_total']),
        ];
    }
}
