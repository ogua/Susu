<?php

namespace App\Filament\Resources\GroupLoans\Pages;

use App\Actions\GroupLoans\IssueGroupMemberLoanAction;
use App\Enums\LoanFrequency;
use App\Filament\Resources\GroupLoans\GroupLoanResource;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateGroupLoan extends CreateRecord
{
    protected static string $resource = GroupLoanResource::class;

    /**
     * Routed through IssueGroupMemberLoanAction (never a raw Eloquent create)
     * so the schedule spread, the one-active-loan guard, and company scoping
     * all match the API/sync paths. The action keys its ValidationException by
     * the raw input name; Filament renders field errors under "data.", so we
     * re-map before rethrowing.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(IssueGroupMemberLoanAction::class)->execute(
                issuedBy: Filament::auth()->user(),
                loanGroup: LoanGroup::findOrFail($data['loan_group_id']),
                customer: Customer::findOrFail($data['customer_id']),
                principal: Money::toMinorUnits($data['principal_amount']),
                securityDeposit: Money::toMinorUnits($data['security_deposit_amount'] ?? 0),
                periodicAmount: Money::toMinorUnits($data['periodic_amount']),
                frequency: LoanFrequency::from($data['repayment_frequency']),
                startDate: Carbon::parse($data['start_date']),
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
