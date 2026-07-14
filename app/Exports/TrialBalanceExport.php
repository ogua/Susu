<?php

namespace App\Exports;

use App\Actions\Reports\BuildTrialBalanceAction;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Money;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TrialBalanceExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Company $company) {}

    public function collection()
    {
        return app(BuildTrialBalanceAction::class)->execute($this->company)['accounts'];
    }

    public function headings(): array
    {
        return ['Code', 'Name', 'Type', 'Debit', 'Credit'];
    }

    /**
     * @param  LedgerAccount  $account
     */
    public function map($account): array
    {
        return [
            $account->code,
            $account->name,
            $account->type->value,
            $account->type->normalBalance() === 'debit' ? Money::format($account->balance) : '',
            $account->type->normalBalance() === 'credit' ? Money::format($account->balance) : '',
        ];
    }
}
