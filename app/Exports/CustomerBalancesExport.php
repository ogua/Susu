<?php

namespace App\Exports;

use App\Actions\Reports\BuildCustomerBalancesAction;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Support\Money;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CustomerBalancesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Branch $branch) {}

    public function collection()
    {
        return app(BuildCustomerBalancesAction::class)->execute($this->branch)['accounts'];
    }

    public function headings(): array
    {
        return ['Account #', 'Customer', 'Phone', 'Product', 'Agent', 'Status', 'Balance'];
    }

    /**
     * @param  SavingsAccount  $account
     */
    public function map($account): array
    {
        return [
            $account->account_number,
            $account->customer->fullName(),
            $account->customer->phone,
            $account->product?->name,
            $account->agent?->name,
            $account->status->value,
            Money::format($account->balance),
        ];
    }
}
