<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Actions\Loans\ApplyForLoanAction;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Customer;
use App\Models\LoanProduct;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    /**
     * Routed through ApplyForLoanAction (never a raw Eloquent create) so a
     * loan application made in Filament snapshots the product's terms and
     * validates amount/product scoping exactly like the API/sync paths do.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ApplyForLoanAction::class)->execute(
            submittedBy: Filament::auth()->user(),
            customer: Customer::findOrFail($data['customer_id']),
            product: LoanProduct::findOrFail($data['loan_product_id']),
            requestedAmount: (int) round((float) $data['amount'] * 100),
            guarantorName: $data['guarantor_name'] ?: null,
            guarantorPhone: $data['guarantor_phone'] ?: null,
            notes: $data['notes'] ?: null,
        );
    }
}
