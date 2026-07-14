<?php

namespace App\Exports;

use App\Actions\Reports\BuildCashPositionAction;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Money;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CashPositionExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Branch $branch) {}

    public function collection()
    {
        return app(BuildCashPositionAction::class)->execute($this->branch)['accounts'];
    }

    public function headings(): array
    {
        return ['Account', 'Held By', 'Cash In Hand'];
    }

    /**
     * @param  LedgerAccount  $account
     */
    public function map($account): array
    {
        return [
            $account->name,
            $account->accountable_type === User::class ? 'Agent' : 'Branch Office',
            Money::format($account->balance),
        ];
    }
}
