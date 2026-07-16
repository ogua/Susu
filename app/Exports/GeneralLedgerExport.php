<?php

namespace App\Exports;

use App\Actions\Reports\BuildGeneralLedgerAction;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class GeneralLedgerExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Company $company,
        private readonly ?LedgerAccount $account = null,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public function collection()
    {
        return app(BuildGeneralLedgerAction::class)
            ->execute($this->company, $this->account, $this->from, $this->to)['rows'];
    }

    public function headings(): array
    {
        return $this->account !== null
            ? ['Date', 'Reference', 'Type', 'Description', 'Debit', 'Credit', 'Balance']
            : ['Code', 'Account', 'Type', 'Transactions', 'Debits', 'Credits', 'Net Movement'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function map($row): array
    {
        if ($this->account !== null) {
            $line = $row['line'];

            return [
                $line->entry->recorded_at->format('Y-m-d H:i'),
                $line->entry->reference,
                $line->entry->type->value,
                $line->memo ?? $line->entry->description,
                $line->debit > 0 ? Money::format($line->debit) : '',
                $line->credit > 0 ? Money::format($line->credit) : '',
                Money::format($row['running']),
            ];
        }

        return [
            $row['account']->code,
            $row['account']->name,
            $row['account']->type->value,
            $row['txn_count'],
            Money::format($row['debit_total']),
            Money::format($row['credit_total']),
            Money::format($row['net']),
        ];
    }
}
