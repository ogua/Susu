<?php

namespace App\Filament\Resources\SavingsAccounts\Pages;

use App\Actions\Savings\OpenSavingsAccountAction;
use App\Filament\Resources\SavingsAccounts\SavingsAccountResource;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;

class CreateSavingsAccount extends CreateRecord
{
    protected static string $resource = SavingsAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): SavingsAccount
    {
        return app(OpenSavingsAccountAction::class)->execute(
            customer: Customer::findOrFail($data['customer_id']),
            product: SavingsProduct::findOrFail($data['savings_product_id']),
            agent: isset($data['agent_id']) ? User::find($data['agent_id']) : null,
            contributionAmount: $data['contribution_amount'],
            targetAmount: $data['target_amount'] ?? null,
            maturesAt: isset($data['matures_at']) ? Carbon::parse($data['matures_at']) : null,
        );
    }
}
