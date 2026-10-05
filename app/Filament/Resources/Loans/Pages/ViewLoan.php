<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanActions;
use App\Filament\Resources\Loans\LoanResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public function getTitle(): string
    {
        return 'Loan '.$this->getRecord()->loan_number;
    }

    protected function getHeaderActions(): array
    {
        // Every action changes the figures on this page, so reload the record afterwards.
        $refresh = fn (Action $action): Action => $action->after(fn () => $this->getRecord()->refresh()->load('installments'));

        return [
            $refresh(LoanActions::approve()),
            $refresh(LoanActions::disburse()),
            $refresh(LoanActions::recordRepayment()),
            ActionGroup::make(array_map($refresh, [
                LoanActions::reject(),
                LoanActions::recalculateSchedule(),
                LoanActions::restructure(),
                LoanActions::topUp(),
                LoanActions::writeOff(),
            ]))->label('More')->button()->color('gray'),
        ];
    }
}
