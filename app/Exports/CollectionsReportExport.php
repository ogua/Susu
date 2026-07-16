<?php

namespace App\Exports;

use App\Actions\Reports\BuildCollectionsReportAction;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CollectionsReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        return app(BuildCollectionsReportAction::class)
            ->execute($this->branch, $this->from, $this->to)['entries'];
    }

    public function headings(): array
    {
        return ['Date', 'Reference', 'Agent', 'Description', 'Method', 'Status', 'Amount'];
    }

    /**
     * @param  JournalEntry  $entry
     */
    public function map($entry): array
    {
        return [
            $entry->recorded_at->format('Y-m-d H:i'),
            $entry->reference,
            $entry->recordedBy?->name ?? 'Unknown',
            $entry->description,
            $entry->payment_method->value,
            $entry->status->value,
            Money::format((int) $entry->amount_sum),
        ];
    }
}
