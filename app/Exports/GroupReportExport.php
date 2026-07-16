<?php

namespace App\Exports;

use App\Actions\Reports\BuildGroupReportAction;
use App\Models\Branch;
use App\Models\Group;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class GroupReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        return app(BuildGroupReportAction::class)
            ->execute($this->branch, $this->from, $this->to)['rows'];
    }

    public function headings(): array
    {
        return ['Group', 'Code', 'Status', 'Members', 'Round', 'Round Expected', 'Round Collected', 'Rounds Paid Out', 'Collected In Period', 'Lifetime Collected'];
    }

    /**
     * @param  array{group: Group, members_count: int, current_round: ?int, round_expected: int, round_collected: int, lifetime_collected: int, rounds_paid_out: int, collected_in_period: int}  $row
     */
    public function map($row): array
    {
        return [
            $row['group']->name,
            $row['group']->code,
            $row['group']->status->value,
            $row['members_count'],
            $row['current_round'] ?? '—',
            Money::format($row['round_expected']),
            Money::format($row['round_collected']),
            $row['rounds_paid_out'],
            Money::format($row['collected_in_period']),
            Money::format($row['lifetime_collected']),
        ];
    }
}
