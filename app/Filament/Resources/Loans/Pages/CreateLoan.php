<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Actions\Loans\ApplyForLoanAction;
use App\Actions\Loans\LoanApplicationDetails;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Schemas\LoanApplicationFields;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;

class CreateLoan extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = LoanResource::class;

    protected static ?string $title = 'New Loan Application';

    /**
     * @return array<int, Step>
     */
    protected function getSteps(): array
    {
        return LoanApplicationFields::steps();
    }

    /**
     * Routed through ApplyForLoanAction (never a raw Eloquent create) so a
     * loan application made in Filament snapshots the product's terms and
     * validates amount/product scoping exactly like the API/sync paths do.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $customer = Customer::findOrFail($data['customer_id']);

        return app(ApplyForLoanAction::class)->execute(
            submittedBy: Filament::auth()->user(),
            customer: $customer,
            product: $product,
            requestedAmount: Money::toMinorUnits((float) $data['amount']),
            savingsAccount: filled($data['savings_account_id'] ?? null)
                ? SavingsAccount::where('customer_id', $customer->id)->find($data['savings_account_id'])
                : null,
            notes: $data['notes'] ?? null,
            details: LoanApplicationDetails::fromArray(LoanApplicationFields::toApplicationDetails($data, $product)),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
