<?php

namespace App\Filament\Resources\GroupLoans\Pages;

use App\Actions\GroupLoans\ApplyForGroupLoanAction;
use App\Filament\Resources\GroupLoans\GroupLoanResource;
use App\Models\LoanGroup;
use App\Models\LoanProduct;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateGroupLoan extends CreateRecord
{
    protected static string $resource = GroupLoanResource::class;

    /**
     * Routed through ApplyForGroupLoanAction (never a raw Eloquent create) so
     * a group loan application made in Filament snapshots the product's terms
     * and validates amount/product/membership scoping exactly like the
     * API/sync paths do.
     *
     * The action keys its ValidationException by the raw input name
     * (loan_group_id, amount, ...); Filament only renders field errors under
     * the "data." state path, so we re-map the keys before rethrowing —
     * otherwise a failed guard just aborts the submit with nothing on screen.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ApplyForGroupLoanAction::class)->execute(
                submittedBy: Filament::auth()->user(),
                loanGroup: LoanGroup::findOrFail($data['loan_group_id']),
                product: LoanProduct::findOrFail($data['loan_product_id']),
                requestedAmount: (int) round((float) $data['amount'] * 100),
                notes: $data['notes'] ?: null,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->keyBy(fn (array $messages, string $key): string => "data.{$key}")
                    ->all()
            );
        }
    }
}
